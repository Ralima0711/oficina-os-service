<?php

namespace App\Application\Composicao;

use App\Domain\OrdemServico\Entities\OrdemServico;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * API Composition — a consulta agregada da OS.
 *
 * O enunciado pede uma consulta que junte o que esta espalhado pelos tres
 * servicos: a OS e o estado da saga aqui, o orcamento no Billing, a timeline
 * de reparo na Execucao. O padrao da Aula e API Composition: alguem chama os
 * participantes e junta a resposta. A alternativa seria uma segunda projecao,
 * que a aula desaconselha quando a consulta e pontual.
 *
 * Por que o compositor fica no OS Service e nao no Kong: o Kong roda em modo
 * DB-less, com a configuracao inteira versionada num ConfigMap, e agregar ali
 * exigiria plugin Lua proprio. O ganho seria o nome do componente; o custo,
 * uma peca nova em Lua a quatro dias da entrega. O OS Service ja e o dono da
 * OS e ja e quem responde "status e historico" no enunciado.
 *
 * TRES REGRAS, e elas sao o que separa composicao de acoplamento:
 *
 *   1. Isto e LEITURA. Nenhuma escrita, nenhum efeito, nenhum passo de saga.
 *      A saga continua inteiramente por mensageria.
 *
 *   2. Participante fora do ar NAO derruba a consulta. O que ele traria vem
 *      null e a resposta diz, em `parciais`, o que faltou e por que. Uma
 *      consulta agregada que exige os tres no ar de pe transforma tres
 *      servicos independentes num so, com a disponibilidade do pior deles.
 *
 *   3. Timeout curto, e as chamadas nao se somam. Dois participantes a dois
 *      segundos sao dois segundos, nao quatro: o Http::pool dispara as duas
 *      ao mesmo tempo.
 *
 *   4. Circuit Breaker, da Aula 2. Timeout cuida de uma chamada; o disjuntor
 *      cuida da enesima. Com um participante fora do ar, sem ele toda consulta
 *      continua esperando os dois segundos inteiros para falhar igual — e as
 *      tentativas de quem espera atrasam a recuperacao de quem caiu. Depois de
 *      N falhas seguidas as chamadas param de sair, e a resposta vem parcial na
 *      hora em vez de parcial dois segundos depois.
 */
class ComporOrdemServico
{
    public function __construct(private readonly DisjuntorDeChamadas $disjuntor) {}

    /**
     * @param  array<string, mixed>  $os  representacao da OS, como a API ja devolve
     * @return array<string, mixed>
     */
    public function compor(array $os, OrdemServico $entidade): array
    {
        $orcamentoId = $entidade->getOrcamentoId();
        $execucaoId = $entidade->getExecucaoId();

        $parciais = [];
        $orcamento = null;
        $execucao = null;

        $respostas = $this->buscarEmParalelo($entidade->getId(), $orcamentoId);

        if ($orcamentoId === null) {
            // Nao e falha: a OS ainda nao chegou a ter orcamento.
            $parciais['orcamento'] = 'ainda nao existe orcamento para esta OS';
        } else {
            [$orcamento, $erro] = $this->ler($respostas['orcamento'] ?? null, 'billing');
            if ($erro !== null) {
                $parciais['orcamento'] = $erro;
            }
        }

        [$execucao, $erro] = $this->ler($respostas['execucao'] ?? null, 'execucao');

        if ($erro !== null) {
            $parciais['execucao'] = $erro;
        } elseif ($execucao === null || $execucao === []) {
            $execucao = null;
            $parciais['execucao'] = $execucaoId === null
                ? 'a OS ainda nao entrou em execucao'
                : 'a Execucao nao tem timeline para esta OS';
        }

        $composta = $os + [
            'orcamento' => $orcamento,
            'execucao' => $execucao,
        ];

        // `parciais` so aparece quando ha algo a declarar. Resposta completa
        // nao carrega um campo vazio para o cliente ter que ignorar.
        if ($parciais !== []) {
            $composta['parciais'] = $parciais;
            Log::info('Consulta agregada devolvida parcial', [
                'osId' => $entidade->getId(),
                'parciais' => array_keys($parciais),
            ]);
        }

        return $composta;
    }

    /**
     * As duas chamadas saem juntas. Em serie, o pior caso seria a soma dos
     * timeouts; em paralelo, e o maior deles.
     *
     * @return array<string, mixed>
     */
    private function buscarEmParalelo(string $osId, ?string $orcamentoId): array
    {
        $timeout = (float) config('services.composicao.timeout', 2.0);
        $billing = rtrim((string) config('services.billing.url'), '/');
        $execucao = rtrim((string) config('services.execucao.url'), '/');

        // Participante com o circuito aberto nao e chamado. A leitura abaixo
        // encontra a resposta ausente e declara a parcial, como em qualquer
        // outra falha — para quem consulta, o efeito e o mesmo; o que muda e
        // que ninguem esperou por ela.
        $chamarBilling = $orcamentoId !== null && $this->disjuntor->permite('billing');
        $chamarExecucao = $this->disjuntor->permite('execucao');

        if (! $chamarBilling && ! $chamarExecucao) {
            return [];
        }

        try {
            $respostas = Http::pool(function ($pool) use ($osId, $orcamentoId, $billing, $execucao, $timeout, $chamarBilling, $chamarExecucao) {
                $chamadas = [];

                if ($chamarBilling) {
                    $chamadas[] = $pool->as('orcamento')
                        ->timeout($timeout)
                        ->acceptJson()
                        ->get("{$billing}/api/orcamentos/{$orcamentoId}");
                }

                if ($chamarExecucao) {
                    $chamadas[] = $pool->as('execucao')
                        ->timeout($timeout)
                        ->acceptJson()
                        ->get("{$execucao}/api/execucao/os/{$osId}/timeline");
                }

                return $chamadas;
            });
        } catch (Throwable $e) {
            // O pool inteiro falhou (DNS, rede). Cada leitura abaixo vira
            // parcial, com o motivo.
            Log::warning('Composicao: o pool de chamadas falhou', [
                'osId' => $osId,
                'erro' => $e->getMessage(),
            ]);

            return [];
        }

        return $respostas;
    }

    /**
     * Traduz uma resposta do pool em [dados, motivo-da-falha].
     *
     * @return array{0: mixed, 1: string|null}
     */
    private function ler(mixed $resposta, string $servico): array
    {
        if ($resposta === null) {
            // Ausente porque o circuito esta aberto, ou porque o pool falhou
            // antes de chamar. Nos dois casos nao ha veredito novo a dar ao
            // disjuntor: contar de novo uma falha que ele ja contou so
            // prolongaria a abertura.
            return [
                null,
                $this->disjuntor->permite($servico)
                    ? "o {$servico} nao respondeu"
                    : "o {$servico} esta com o circuito aberto apos falhas seguidas",
            ];
        }

        if ($resposta instanceof Throwable) {
            Log::warning('Composicao: participante indisponivel', [
                'servico' => $servico,
                'erro' => $resposta->getMessage(),
            ]);

            $this->disjuntor->registrarFalha($servico);

            return [null, "o {$servico} esta indisponivel"];
        }

        if ($resposta->status() === 404) {
            // Nao e falha do participante: ele respondeu que nao tem.
            $this->disjuntor->registrarSucesso($servico);

            return [null, null];
        }

        if (! $resposta->successful()) {
            Log::warning('Composicao: participante devolveu erro', [
                'servico' => $servico,
                'status' => $resposta->status(),
            ]);

            // 5xx conta como falha; 4xx nao. Erro de cliente e problema do
            // pedido, nao saude do participante — abrir o circuito por causa
            // dele cortaria um servico que esta de pe.
            if ($resposta->serverError()) {
                $this->disjuntor->registrarFalha($servico);
            }

            return [null, "o {$servico} respondeu {$resposta->status()}"];
        }

        $this->disjuntor->registrarSucesso($servico);

        return [$resposta->json(), null];
    }
}
