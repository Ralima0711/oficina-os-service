<?php

namespace App\Application\Saga;

use App\Application\Messaging\Envelope;
use App\Infrastructure\Messaging\TraceContextCarrier;
use App\Domain\OrdemServico\Entities\OrdemServico;
use App\Domain\OrdemServico\Exceptions\LostUpdateException;
use App\Domain\OrdemServico\Repositories\OrdemServicoRepositoryInterface;
use App\Domain\Veiculo\Repositories\VeiculoRepositoryInterface;
use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestrador da Saga — hospedado no OS Service.
 *
 * Cobre os cinco passos do contrato v1.1, Parte 3b:
 *
 *   1. GerarOrcamento      → orcamento.gerado | orcamento.falhou
 *   2. (espera humana)     → orcamento.aprovado | orcamento.recusado | aprovacao.expirada
 *   3. CobrarPagamento     → pagamento.confirmado | pagamento.recusado
 *   4. EnviarParaExecucao  → execucao.iniciada → execucao.concluida | execucao.falhou
 *   5. (anúncio)           → os.finalizada | os.cancelada
 *
 * O orquestrador COORDENA, NÃO DECIDE (ADR-0008). Toda regra de negócio vive na
 * entidade OrdemServico; aqui só se lê evento, se escolhe o próximo passo e se
 * grava a intenção de publicá-lo.
 *
 * Três invariantes sustentam este arquivo:
 *
 *   Idempotência (Parte 5, regra 1) — o messageId é gravado DENTRO da mesma
 *   transação que aplica os efeitos. Rollback desfaz os dois.
 *
 *   Ordem (Parte 5, regra 7) — antes de qualquer efeito, confere sagaId ativo,
 *   estado compatível e id do recurso vigente. Falhou, descarta e confirma a
 *   mensagem: não é erro, não vai para DLQ, não força transição.
 *
 *   Atomicidade de saída — nenhuma publicação acontece aqui. A intenção vai
 *   para o outbox, na mesma transação. Ver Outbox e OutboxPublisher.
 */
class Orchestrator
{
    // Filas de comando — contrato v1.1, Parte 2.
    private const FILA_GERAR_ORCAMENTO = 'billing.gerar-orcamento';
    private const FILA_COBRAR_PAGAMENTO = 'billing.cobrar-pagamento';
    private const FILA_ANULAR_ORCAMENTO = 'billing.anular-orcamento';
    private const FILA_ESTORNAR_PAGAMENTO = 'billing.estornar-pagamento';
    private const FILA_ENVIAR_EXECUCAO = 'execucao.enviar-para-execucao';
    private const FILA_CANCELAR_EXECUCAO = 'execucao.cancelar-execucao';

    /**
     * A sala de espera do ADR-0010. Nao tem consumidor, de proposito: a
     * mensagem fica parada ate o x-message-ttl de 7 dias vencer, e o
     * dead-letter a manda para o exchange oficina.espera com routing key
     * aprovacao.expirada, de onde os.saga.eventos a recebe.
     *
     * Quem conta o tempo e o broker, nao a aplicacao. Nao ha job agendado.
     */
    private const FILA_AGUARDANDO_APROVACAO = 'billing.aguardando-aprovacao';

    /** 7 dias, o mesmo x-message-ttl da fila no definitions.json. */
    private const DIAS_PARA_APROVAR = 7;

    public function __construct(
        private OrdemServicoRepositoryInterface $osRepository,
        private Outbox $outbox,
        // Veiculo vive neste mesmo servico (ADR-0007): resolver a placa aqui
        // nao cruza fronteira nenhuma.
        private VeiculoRepositoryInterface $veiculos,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    //  Passo 1 — Gerar orçamento
    // ═══════════════════════════════════════════════════════════════

    /**
     * Inicia a saga: o diagnóstico foi concluído, o orçamento é pedido.
     *
     * É o único ponto de entrada que não vem de um evento — vem da API, quando
     * o mecânico fecha o diagnóstico.
     *
     * @param array $itensDiagnosticados obrigatório, mínimo 1 item (contrato v1.1)
     */
    public function iniciarSaga(string $osId, array $itensDiagnosticados): string
    {
        if ($itensDiagnosticados === []) {
            // Orçamento vazio sairia com valorTotal 0, a cobrança recusaria com
            // 422 e a OS ficaria presa em AGUARDANDO_PAGAMENTO para sempre.
            // Diagnóstico sem nada a orçar não é orçamento vazio: é falha.
            throw new \DomainException(
                "OS {$osId}: diagnóstico sem itens não gera orçamento. "
                . 'Publique orcamento.falhou com motivo sem_itens_a_orcar.'
            );
        }

        $sagaId = (string) \Ramsey\Uuid\Uuid::uuid4();

        DB::transaction(function () use ($osId, $itensDiagnosticados, $sagaId) {
            $os = $this->carregarOu404($osId);

            $os->concluirDiagnostico();                       // T2
            $this->osRepository->save($os);

            DB::table('saga_estado')->insert([
                'saga_id' => $sagaId,
                'os_id' => $osId,
                'status' => 'EM_ANDAMENTO',
                'passo_atual' => 1,
                'criada_em' => now(),
            ]);

            $envelope = Envelope::create(
                type: 'GerarOrcamento',
                body: [
                    'osId' => $osId,
                    'clienteId' => $os->getClienteId(),
                    'veiculo' => ['placa' => $this->placaDo($os->getVeiculoId())],
                    'descricaoProblema' => $os->getDescricao(),
                    'itensDiagnosticados' => $itensDiagnosticados,
                ],
                sagaId: $sagaId,
                traceparent: $this->traceparentAtual(),
            );

            $this->outbox->comando(self::FILA_GERAR_ORCAMENTO, $envelope, $osId);
        });

        Log::info('Saga: iniciada', ['osId' => $osId, 'sagaId' => $sagaId]);

        return $sagaId;
    }

    /**
     * Placa do veiculo da OS.
     *
     * O comando GerarOrcamento leva `veiculo.placa`, e ate 12/11 este metodo
     * nao existia: o orquestrador mandava o PROPRIO veiculoId no lugar da
     * placa. O Billing valida so que e string nao vazia, entao um uuid passava
     * — o orcamento saia com um uuid no campo placa e ninguem via.
     *
     * Agora da para resolver de verdade: o ADR-0007 coloca Veiculo neste mesmo
     * servico, entao nao ha fronteira a cruzar.
     *
     * Sem veiculo cadastrado, devolve o id e REGISTRA. OS criada antes das
     * tabelas de cliente e veiculo existirem cai aqui; degradar em silencio
     * seria repetir o erro que este arquivo inteiro passou o dia corrigindo.
     */
    private function placaDo(string $veiculoId): string
    {
        $veiculo = $this->veiculos->findById($veiculoId);

        if ($veiculo !== null) {
            return (string) $veiculo->getPlaca();
        }

        Log::warning('Saga: veiculo nao encontrado, placa nao resolvida', [
            'veiculoId' => $veiculoId,
        ]);

        return $veiculoId;
    }

    /** orcamento.gerado → AGUARDANDO_APROVACAO. Nada é publicado: espera humana. */
    public function onOrcamentoGerado(Envelope $env): void
    {
        $this->processar($env, StatusOrdem::AGUARDANDO_ORCAMENTO, function (OrdemServico $os, array $body) use ($env) {
            $os->registrarOrcamentoGerado(
                $body['orcamentoId'],
                (float) $body['valorTotal'],
            );                                                 // T3
            $this->avancarPasso($env->getSagaId(), 2);

            $this->armarExpiracaoDaAprovacao($os, $env);
        });
    }

    /**
     * Arma o prazo de aprovacao do ADR-0010.
     *
     * Ate 12/11 isto NAO EXISTIA e o timeout inteiro estava inerte: a fila
     * billing.aguardando-aprovacao tinha o TTL de 7 dias e o dead-letter
     * configurados no definitions.json, o orquestrador tinha
     * onAprovacaoExpirada, o roteamento mapeava aprovacao.expirada e havia
     * teste cobrindo — e NADA publicava na sala de espera. Nem binding, nem
     * codigo, nos dois servicos. Tudo escrito, nada ligado.
     *
     * O que entra na sala de espera JA E o evento aprovacao.expirada, com o
     * corpo que o orquestrador vai receber. Soa estranho publicar um evento
     * que ainda nao aconteceu, mas e assim que mensagem atrasada por TTL
     * funciona: quem decide QUANDO entregar e o broker. O
     * ConsumirEventosSaga roteia pelo type do envelope, nao pela routing key,
     * entao o type precisa ser o final desde a partida.
     *
     * Vai pelo outbox, como todo o resto: a intencao entra na MESMA transacao
     * que mudou a OS para AGUARDANDO_APROVACAO. Prazo armado sem a mudanca de
     * estado, ou o contrario, seria exatamente o que o outbox existe para
     * impedir.
     *
     * Aprovacao antes do prazo nao desarma nada — a mensagem continua la e
     * expira do mesmo jeito. O que protege e a guarda de onAprovacaoExpirada:
     * ela so aceita a OS em AGUARDANDO_APROVACAO e so com o orcamentoId
     * vigente. Expiracao de orcamento ja substituido, ou de OS que seguiu
     * adiante, e descartada com log.
     */
    private function armarExpiracaoDaAprovacao(OrdemServico $os, Envelope $env): void
    {
        $expiraEm = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . self::DIAS_PARA_APROVAR . ' days')
            ->format('Y-m-d\TH:i:s\Z');

        $this->outbox->comando(
            self::FILA_AGUARDANDO_APROVACAO,
            Envelope::create(
                type: 'aprovacao.expirada',
                body: [
                    'osId' => $os->getId(),
                    'orcamentoId' => $os->getOrcamentoId(),
                    'expirouEm' => $expiraEm,
                ],
                sagaId: $env->getSagaId(),
                causationId: $env->getMessageId(),
                traceparent: $this->traceparentAtual($env),
            ),
            $os->getId(),
        );
    }

    /** orcamento.falhou → CANCELADA. Nada a compensar: nenhum orçamento existe. */
    public function onOrcamentoFalhou(Envelope $env): void
    {
        $this->processar($env, StatusOrdem::AGUARDANDO_ORCAMENTO, function (OrdemServico $os, array $body) use ($env) {
            $os->cancelarPorFalhaOrcamento();                  // T4
            $this->encerrar($os, $env->getSagaId(), 'CANCELADA', $body['motivo'] ?? 'orcamento_falhou');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  Passo 2 — Decisão do cliente
    // ═══════════════════════════════════════════════════════════════

    /** orcamento.aprovado → APROVADA → AGUARDANDO_PAGAMENTO, e cobra. */
    public function onOrcamentoAprovado(Envelope $env): void
    {
        // Duas transações, de propósito.
        //
        // O doc da máquina de estados (item 6 da revisão do contrato) e a guarda
        // de T7 exigem que APROVADA esteja PERSISTIDA antes de o CobrarPagamento
        // sair. Aplicar T5 e T7 na mesma transação faria APROVADA nunca existir
        // no banco: seriam 9 estados observáveis, não 10.
        //
        // O preço é uma janela entre as duas transações. Ela é fechada deixando
        // a marca de idempotência para a SEGUNDA: se o processo morrer no meio,
        // a mensagem não está marcada, o RabbitMQ reentrega, e o estado aceito
        // abaixo inclui APROVADA — a retomada pula o T5 já aplicado e segue para
        // o T7. Marcar na primeira deixaria a OS presa em APROVADA para sempre.
        $this->processar($env, [StatusOrdem::AGUARDANDO_APROVACAO], function (OrdemServico $os, array $body) {
            $this->exigirOrcamentoVigente($os, $body);

            $os->aprovar();                                    // T5
        }, marcarProcessada: false);

        $this->processar($env, [StatusOrdem::APROVADA], function (OrdemServico $os, array $body) use ($env) {
            $this->exigirOrcamentoVigente($os, $body);

            $os->iniciarCobranca();                            // T7

            $comando = Envelope::create(
                type: 'CobrarPagamento',
                body: [
                    'osId' => $os->getId(),
                    'orcamentoId' => $os->getOrcamentoId(),
                    'valorTotal' => $os->getValorTotal(),
                    'moeda' => 'BRL',
                ],
                sagaId: $env->getSagaId(),
                causationId: $env->getMessageId(),
                traceparent: $this->traceparentAtual($env),
            );

            $this->outbox->comando(self::FILA_COBRAR_PAGAMENTO, $comando, $os->getId());
            $this->avancarPasso($env->getSagaId(), 3);
        });
    }

    /** orcamento.recusado → CANCELADA, anulando o orçamento. */
    public function onOrcamentoRecusado(Envelope $env): void
    {
        $this->compensarEEncerrar($env, StatusOrdem::AGUARDANDO_APROVACAO, 'orcamento_recusado');
    }

    /**
     * aprovacao.expirada → CANCELADA, anulando o orçamento.
     *
     * A expiração chega pelo TTL da fila do RabbitMQ, então pode chegar tarde:
     * o cliente pode ter aprovado nos últimos minutos do sétimo dia. Por isso a
     * guarda de estado e de orcamentoId vigente vale aqui mais que em qualquer
     * outro ponto — expiração atrasada não cancela OS já paga.
     */
    public function onAprovacaoExpirada(Envelope $env): void
    {
        $this->compensarEEncerrar($env, StatusOrdem::AGUARDANDO_APROVACAO, 'aprovacao_expirada');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Passo 3 — Pagamento
    // ═══════════════════════════════════════════════════════════════

    /** pagamento.confirmado → EM_EXECUCAO, e manda executar. */
    public function onPagamentoConfirmado(Envelope $env): void
    {
        $this->processar($env, StatusOrdem::AGUARDANDO_PAGAMENTO, function (OrdemServico $os, array $body) use ($env) {
            $this->exigirOrcamentoVigente($os, $body);

            $os->confirmarPagamento($body['pagamentoId']);     // T8

            $comando = Envelope::create(
                type: 'EnviarParaExecucao',
                body: [
                    'osId' => $os->getId(),
                    'orcamentoId' => $os->getOrcamentoId(),
                    'prioridade' => 1,
                    'itens' => [],
                ],
                sagaId: $env->getSagaId(),
                causationId: $env->getMessageId(),
                traceparent: $this->traceparentAtual($env),
            );

            $this->outbox->comando(self::FILA_ENVIAR_EXECUCAO, $comando, $os->getId());
            $this->avancarPasso($env->getSagaId(), 4);
        });
    }

    /** pagamento.recusado → CANCELADA, anulando o orçamento. Nada a estornar. */
    public function onPagamentoRecusado(Envelope $env): void
    {
        $this->compensarEEncerrar($env, StatusOrdem::AGUARDANDO_PAGAMENTO, 'pagamento_recusado');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Passo 4 — Execução
    // ═══════════════════════════════════════════════════════════════

    /** execucao.iniciada — só registra quem pegou. O estado já é EM_EXECUCAO. */
    public function onExecucaoIniciada(Envelope $env): void
    {
        $this->processar($env, StatusOrdem::EM_EXECUCAO, function (OrdemServico $os, array $body) {
            $os->setExecucaoId($body['execucaoId']);

            Log::info('Saga: mecânico puxou a OS', [
                'osId' => $os->getId(),
                'mecanicoId' => $body['mecanicoId'] ?? null,
            ]);
        });
    }

    /** execucao.concluida → FINALIZADA. Fim do caminho feliz. */
    public function onExecucaoConcluida(Envelope $env): void
    {
        $this->processar($env, StatusOrdem::EM_EXECUCAO, function (OrdemServico $os) use ($env) {
            $os->finalizar();                                  // T10
            $this->encerrar($os, $env->getSagaId(), 'FINALIZADA');
        });
    }

    /**
     * execucao.falhou → CANCELADA, com a compensação completa.
     *
     * É o ponto mais fundo da saga, e por isso o que tem mais a desfazer. A
     * ordem é a inversa dos passos (contrato v1.1, Parte 3b):
     *
     *   CancelarExecucao → EstornarPagamento → AnularOrcamento → CANCELADA
     *
     * O CancelarExecucao vai mesmo tendo sido a Execução quem falhou: ela pode
     * ter falhado depois de enfileirar, e o comando é idempotente — execução já
     * concluída responde sucesso sem desfazer nada.
     */
    public function onExecucaoFalhou(Envelope $env): void
    {
        $this->processar($env, StatusOrdem::EM_EXECUCAO, function (OrdemServico $os, array $body) use ($env) {
            $motivo = $body['motivo'] ?? 'execucao_falhou';

            $os->cancelarPorFalhaExecucao();                   // T11

            $this->compensar(self::FILA_CANCELAR_EXECUCAO, 'CancelarExecucao', $os, $env, $motivo);

            if ($os->getPagamentoId() !== null) {
                $this->compensar(self::FILA_ESTORNAR_PAGAMENTO, 'EstornarPagamento', $os, $env, $motivo);
            }

            $this->compensar(self::FILA_ANULAR_ORCAMENTO, 'AnularOrcamento', $os, $env, $motivo);

            $this->encerrar($os, $env->getSagaId(), 'CANCELADA', $motivo);
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  Núcleo
    // ═══════════════════════════════════════════════════════════════

    /**
     * Executa $acao numa transação, com idempotência e guardas de ordem.
     *
     * @param string $estadoEsperado o estado em que a OS tem de estar para o
     *                               evento valer. Em outro estado, descarta.
     */
    /**
     * @param string|string[] $estadoEsperado estados a partir dos quais o evento é aceito
     * @param bool $marcarProcessada false quando o handler usa mais de uma transação
     *                               e a marca de idempotência pertence à última
     */
    private function processar(
        Envelope $env,
        string|array $estadoEsperado,
        callable $acao,
        bool $marcarProcessada = true,
    ): void {
        $estadosAceitos = (array) $estadoEsperado;

        $body = $env->getBody();
        $osId = $body['osId'] ?? null;

        if ($osId === null) {
            Log::warning('Saga: evento sem osId, descartado', [
                'type' => $env->getType(),
                'messageId' => $env->getMessageId(),
            ]);
            return;
        }

        DB::transaction(function () use ($env, $osId, $body, $estadosAceitos, $acao, $marcarProcessada) {
            // 1. Idempotência, antes de qualquer efeito.
            $repetida = DB::table('mensagens_processadas')
                ->where('message_id', $env->getMessageId())
                ->exists();

            if ($repetida) {
                Log::info('Saga: mensagem repetida descartada', [
                    'messageId' => $env->getMessageId(),
                    'type' => $env->getType(),
                ]);
                return;
            }

            $os = $this->osRepository->findById($osId);

            if ($os === null) {
                Log::warning('Saga: OS não encontrada', ['osId' => $osId, 'type' => $env->getType()]);
                return;
            }

            // 2. Guardas de ordem (Parte 5, regra 7).
            if (!$this->sagaAtiva($env->getSagaId(), $osId)) {
                Log::info('Saga: evento de saga inativa descartado', [
                    'osId' => $osId,
                    'sagaId' => $env->getSagaId(),
                    'type' => $env->getType(),
                ]);
                return;
            }

            $estadoAtual = $os->getStatus()->value();

            if (!in_array($estadoAtual, $estadosAceitos, true)) {
                Log::info('Saga: evento incompatível com o estado atual, descartado', [
                    'osId' => $osId,
                    'type' => $env->getType(),
                    'estadoAtual' => $estadoAtual,
                    'estadosAceitos' => $estadosAceitos,
                ]);
                return;
            }

            // 3. Efeitos — transição, outbox e estado da saga, tudo junto.
            try {
                $acao($os, $body);
            } catch (OrdemFora $e) {
                Log::info('Saga: guarda de recurso vigente recusou o evento', [
                    'osId' => $osId,
                    'type' => $env->getType(),
                    'motivo' => $e->getMessage(),
                ]);
                return;
            }

            $this->osRepository->save($os);

            // 4. Marca a mensagem como processada, na mesma transação.
            // Handler de duas transações marca só na última: ver onOrcamentoAprovado.
            if ($marcarProcessada) {
                DB::table('mensagens_processadas')->insert([
                    'message_id' => $env->getMessageId(),
                    'saga_id' => $env->getSagaId(),
                    'tipo' => $env->getType(),
                    'processado_em' => now(),
                ]);
            }
        });
    }

    /**
     * Caminho comum de três eventos que cancelam anulando o orçamento:
     * recusa do cliente, expiração do prazo e recusa do pagamento.
     */
    private function compensarEEncerrar(Envelope $env, string $estadoEsperado, string $motivo): void
    {
        $this->processar($env, $estadoEsperado, function (OrdemServico $os, array $body) use ($env, $motivo) {
            $this->exigirOrcamentoVigente($os, $body);

            if ($os->getStatus()->equals(StatusOrdem::AGUARDANDO_APROVACAO)) {
                $os->cancelarPorRecusaOrcamento();             // T6
            } else {
                $os->cancelarPorFalhaPagamento();              // T9
            }

            $this->compensar(self::FILA_ANULAR_ORCAMENTO, 'AnularOrcamento', $os, $env, $motivo);
            $this->encerrar($os, $env->getSagaId(), 'CANCELADA', $motivo);
        });
    }

    private function compensar(
        string $fila,
        string $tipo,
        OrdemServico $os,
        Envelope $causa,
        string $motivo,
    ): void {
        $body = ['osId' => $os->getId(), 'motivo' => $motivo];

        if ($os->getOrcamentoId() !== null) {
            $body['orcamentoId'] = $os->getOrcamentoId();
        }
        if ($tipo === 'EstornarPagamento' && $os->getPagamentoId() !== null) {
            $body['pagamentoId'] = $os->getPagamentoId();
        }

        $envelope = Envelope::create(
            type: $tipo,
            body: $body,
            sagaId: $causa->getSagaId(),
            causationId: $causa->getMessageId(),
            traceparent: $this->traceparentAtual($causa),
        );

        $this->outbox->comando($fila, $envelope, $os->getId());
    }

    /** Publica o evento de anúncio e fecha o estado da saga. */
    private function encerrar(
        OrdemServico $os,
        string $sagaId,
        string $estadoFinal,
        ?string $motivo = null,
    ): void {
        $body = [
            'osId' => $os->getId(),
            'sagaId' => $sagaId,
            'estadoFinal' => $estadoFinal,
            'ocorridoEm' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($motivo !== null) {
            $body['motivo'] = $motivo;
        }

        $envelope = Envelope::create(
            type: $estadoFinal === 'CANCELADA' ? 'os.cancelada' : 'os.finalizada',
            body: $body,
            sagaId: $sagaId,
            traceparent: $this->traceparentAtual(),
        );

        $this->outbox->evento($envelope, $os->getId());

        DB::table('saga_estado')
            ->where('saga_id', $sagaId)
            ->update(['status' => $estadoFinal, 'finalizada_em' => now()]);
    }

    /**
     * O traceparent que vai na mensagem gravada no outbox: o do span corrente (o
     * CONSUMER do evento que causou o passo, ou o SERVER da requisição que abriu a
     * saga). O publicador do outbox roda em outro processo, sem span nenhum, e usa
     * este valor como pai do span PRODUCER — é o que mantém a saga num trace só
     * (contrato de observabilidade, regra 10). Sem span corrente, vale o da
     * mensagem que causou o passo; sem nenhum dos dois, o Envelope gera um.
     */
    private function traceparentAtual(?Envelope $causa = null): ?string
    {
        $corrente = TraceContextCarrier::inject()[TraceContextCarrier::TRACEPARENT] ?? null;

        if (is_string($corrente) && $corrente !== '') {
            return $corrente;
        }

        $daCausa = $causa?->getTraceparent();

        return is_string($daCausa) && $daCausa !== '' ? $daCausa : null;
    }

    // ── Guardas ────────────────────────────────────────────────────

    private function sagaAtiva(string $sagaId, string $osId): bool
    {
        return DB::table('saga_estado')
            ->where('saga_id', $sagaId)
            ->where('os_id', $osId)
            ->where('status', 'EM_ANDAMENTO')
            ->exists();
    }

    /**
     * O evento tem de falar do orçamento vigente, não de um anterior.
     * Sem isso, uma expiração atrasada do primeiro orçamento cancelaria uma OS
     * que já está num segundo.
     */
    private function exigirOrcamentoVigente(OrdemServico $os, array $body): void
    {
        $doEvento = $body['orcamentoId'] ?? null;

        if ($doEvento !== null && $doEvento !== $os->getOrcamentoId()) {
            throw new OrdemFora(
                "orcamentoId do evento ({$doEvento}) não é o vigente ({$os->getOrcamentoId()})"
            );
        }
    }

    private function avancarPasso(string $sagaId, int $passo): void
    {
        DB::table('saga_estado')->where('saga_id', $sagaId)->update(['passo_atual' => $passo]);
    }

    private function carregarOu404(string $osId): OrdemServico
    {
        $os = $this->osRepository->findById($osId);

        if ($os === null) {
            throw new \DomainException("OS não encontrada: {$osId}");
        }

        return $os;
    }
}

/**
 * Sinaliza que uma guarda de recurso vigente recusou o evento.
 * Não é erro: o evento é descartado e confirmado, sem efeito e sem DLQ.
 */
class OrdemFora extends \RuntimeException {}
