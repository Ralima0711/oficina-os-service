<?php

namespace App\Application\Composicao;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker das chamadas sincronas entre servicos (Aula 2).
 *
 * O problema que ele resolve nao e o participante cair — para isso basta
 * timeout. E o que acontece DEPOIS: com o Billing fora do ar, toda consulta
 * agregada continua abrindo conexao, esperando os dois segundos inteiros e
 * falhando. O chamador gasta thread, o chamado recebe carga que nao consegue
 * atender, e a recuperacao dele fica mais lenta por causa das tentativas de
 * quem esta esperando. E assim que uma indisponibilidade de um servico vira
 * lentidao em todos.
 *
 * O disjuntor corta isso: depois de N falhas seguidas, o circuito ABRE e as
 * chamadas seguintes nem saem — a resposta vem parcial na hora, em vez de
 * parcial dois segundos depois.
 *
 * Os tres estados classicos:
 *
 *   FECHADO       passa tudo, contando falhas.
 *   ABERTO        recusa na hora, por `segundos_aberto`.
 *   MEIO-ABERTO   o primeiro apos esse prazo passa, de prova. Sucesso fecha e
 *                 zera; falha abre de novo.
 *
 * Meio-aberto aqui nao e um estado guardado: a chave de aberto expira sozinha
 * e a proxima chamada passa. O efeito e o mesmo, sem estado a mais para
 * manter consistente entre pods.
 *
 * O estado vive no cache, e o cache deste servico e `database` — portanto no
 * Postgres, compartilhado pelas replicas. O disjuntor e do servico, nao do
 * pod. Num cache por processo ele ainda funcionaria, mas cada replica
 * aprenderia a queda por conta propria.
 */
class DisjuntorDeChamadas
{
    private const PREFIXO = 'disjuntor';

    /** O circuito deste participante esta fechado (ou meio-aberto)? */
    public function permite(string $servico): bool
    {
        return ! Cache::has($this->chaveAberto($servico));
    }

    public function registrarSucesso(string $servico): void
    {
        // Fecha e zera: sucesso em meio-aberto nao deve deixar falhas
        // antigas contando para a proxima abertura.
        Cache::forget($this->chaveFalhas($servico));
        Cache::forget($this->chaveAberto($servico));
    }

    public function registrarFalha(string $servico): void
    {
        $teto = (int) config('services.composicao.falhas_para_abrir', 3);
        $janela = (int) config('services.composicao.janela_de_falhas', 60);
        $aberto = (int) config('services.composicao.segundos_aberto', 30);

        $chave = $this->chaveFalhas($servico);

        // A janela e o TTL do contador: falhas espacadas no tempo nao se
        // somam. Tres falhas em um minuto e sinal; tres em uma hora e ruido.
        Cache::add($chave, 0, $janela);
        $falhas = (int) Cache::increment($chave);

        if ($falhas < $teto) {
            return;
        }

        Cache::put($this->chaveAberto($servico), true, $aberto);
        Cache::forget($chave);

        Log::warning('Circuito aberto: parando de chamar o participante', [
            'servico' => $servico,
            'falhas' => $falhas,
            'segundos' => $aberto,
        ]);
    }

    private function chaveFalhas(string $servico): string
    {
        return self::PREFIXO.':falhas:'.$servico;
    }

    private function chaveAberto(string $servico): string
    {
        return self::PREFIXO.':aberto:'.$servico;
    }
}
