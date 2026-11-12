<?php

namespace Tests\Feature;

use App\Application\Messaging\Envelope;
use App\Application\Saga\Orchestrator;
use App\Domain\OrdemServico\Entities\OrdemServico;
use App\Domain\OrdemServico\Repositories\OrdemServicoRepositoryInterface;
use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\ExigeBancoDeTeste;
use Tests\TestCase;

/**
 * A propriedade que o outbox existe para garantir: mudança de estado e intenção
 * de publicar entram juntas ou não entram.
 *
 * É o nível de integração da pirâmide — roda contra o Postgres de verdade,
 * porque o que está sendo testado é justamente o comportamento transacional,
 * que mock nenhum reproduz.
 */
class SagaOutboxTest extends TestCase
{
    use ExigeBancoDeTeste;
    use RefreshDatabase;

    private Orchestrator $saga;
    private OrdemServicoRepositoryInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();

        // Postgres de verdade, e num banco DEDICADO: o RefreshDatabase roda
        // migrate:fresh, que derruba todas as tabelas da conexao ativa.
        $this->exigirBancoDeTeste();

        $this->saga = app(Orchestrator::class);
        $this->repo = app(OrdemServicoRepositoryInterface::class);
    }

    /** Id de verdade: as colunas sao uuid, e o Postgres cobra isso. */
    private function uuid(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }

    /**
     * Rotulo legivel -> UUID estavel.
     *
     * As colunas sao `uuid` e o Postgres cobra o tipo, mas o teste precisa que
     * $this->id('o-1') no evento de orcamento gerado seja o MESMO id no evento de
     * aprovacao: e o que o exigirOrcamentoVigente compara. UUID aleatorio a
     * cada chamada quebraria essa ligacao. uuid5 e deterministico — mesmo
     * rotulo, mesmo UUID — e rotulos diferentes nunca colidem.
     *
     * Mantem o rotulo visivel no corpo do teste, que e o que torna o cenario
     * legivel, sem mentir para o banco sobre o tipo.
     */
    private function id(string $rotulo): string
    {
        return \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_OID, $rotulo)->toString();
    }

    // ── Preparação ─────────────────────────────────────────────────

    private function osAguardandoOrcamento(): array
    {
        $os = OrdemServico::criar($this->uuid(), $this->uuid(), 'Barulho no freio');
        $os->iniciarDiagnostico($this->uuid());
        $os->setDiagnostico('Pastilhas no limite');
        $this->repo->save($os);

        $sagaId = $this->saga->iniciarSaga($os->getId(), [
            ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 2],
        ]);

        return [$os->getId(), $sagaId];
    }

    private function evento(string $tipo, string $sagaId, array $body): Envelope
    {
        return Envelope::create(type: $tipo, body: $body, sagaId: $sagaId);
    }

    private function outboxDe(string $osId): array
    {
        return DB::table('saga_outbox')
            ->where('os_id', $osId)
            ->orderBy('id')
            ->get()
            ->map(fn ($l) => ['tipo' => $l->tipo, 'destino' => $l->destino, 'canal' => $l->canal])
            ->all();
    }

    // ── Atomicidade ────────────────────────────────────────────────

    public function test_iniciar_saga_grava_o_comando_junto_com_a_transicao(): void
    {
        [$osId] = $this->osAguardandoOrcamento();

        $this->assertSame(
            StatusOrdem::AGUARDANDO_ORCAMENTO,
            $this->repo->findById($osId)->getStatus()->value()
        );

        $this->assertSame(
            [['tipo' => 'GerarOrcamento', 'destino' => 'billing.gerar-orcamento', 'canal' => 'comando']],
            $this->outboxDe($osId)
        );
    }

    public function test_nada_e_gravado_no_outbox_se_a_transacao_falhar(): void
    {
        $os = OrdemServico::criar($this->uuid(), $this->uuid(), 'x');
        $os->iniciarDiagnostico($this->uuid());
        // Sem diagnóstico: concluirDiagnostico() vai lançar dentro da transação.
        $this->repo->save($os);

        try {
            $this->saga->iniciarSaga($os->getId(), [
                ['descricao' => 'Filtro', 'tipo' => 'peca', 'quantidade' => 1],
            ]);
            $this->fail('devia ter falhado por falta de diagnóstico');
        } catch (\DomainException) {
            // esperado
        }

        $this->assertSame([], $this->outboxDe($os->getId()));
        $this->assertSame(
            StatusOrdem::EM_DIAGNOSTICO,
            $this->repo->findById($os->getId())->getStatus()->value()
        );
        $this->assertDatabaseCount('saga_estado', 0);
    }

    public function test_outbox_recusa_gravacao_fora_de_transacao(): void
    {
        $outbox = app(\App\Application\Saga\Outbox::class);
        $osId = $this->uuid();

        // O RefreshDatabase embrulha CADA teste numa transacao, entao aqui
        // dentro DB::transactionLevel() nunca e 0 e o guard do Outbox — que e
        // justamente o que este teste existe para provar — nao dispararia
        // nunca. Este teste nao podia passar, independente de qualquer outro
        // defeito. Sai-se da transacao do harness so para a asserção e
        // volta-se em seguida, senao o teardown do RefreshDatabase quebra.
        $nivel = DB::transactionLevel();

        for ($i = 0; $i < $nivel; $i++) {
            DB::rollBack();
        }

        try {
            $outbox->comando(
                'billing.gerar-orcamento',
                Envelope::create('GerarOrcamento', ['osId' => $osId], $this->uuid()),
                $osId
            );

            $this->fail('Outbox devia recusar gravacao fora de transacao');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('dentro de uma transação', $e->getMessage());
        } finally {
            for ($i = 0; $i < $nivel; $i++) {
                DB::beginTransaction();
            }
        }
    }

    // ── Prazo de aprovação (ADR-0010) ──────────────────────────────

    /**
     * Ate 12/11 o timeout de 7 dias estava INERTE de ponta a ponta: a fila
     * billing.aguardando-aprovacao tinha TTL e dead-letter no
     * definitions.json, o orquestrador tinha onAprovacaoExpirada, o
     * roteamento mapeava aprovacao.expirada, e havia teste — e NADA publicava
     * na sala de espera. Tudo escrito, nada ligado.
     */
    public function test_orcamento_gerado_arma_o_prazo_de_aprovacao(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        $this->saga->onOrcamentoGerado($this->evento('orcamento.gerado', $sagaId, [
            'osId' => $osId,
            'orcamentoId' => $this->id('o-1'),
            'valorTotal' => 241.80,
        ]));

        // Direto na tabela: o outboxDe() projeta so tipo, destino e canal,
        // porque os testes de ordem comparam a lista inteira. Aqui o que
        // importa e o payload.
        $espera = DB::table('saga_outbox')
            ->where('os_id', $osId)
            ->where('destino', 'billing.aguardando-aprovacao')
            ->first();

        $this->assertNotNull($espera, 'nada foi colocado na sala de espera do ADR-0010');

        $corpo = json_decode($espera->payload, true);

        // O type ja e o final: quem decide QUANDO entregar e o broker, e o
        // ConsumirEventosSaga roteia pelo type do envelope.
        $this->assertSame('aprovacao.expirada', $corpo['header']['type']);
        $this->assertSame($osId, $corpo['body']['osId']);
        $this->assertSame($this->id('o-1'), $corpo['body']['orcamentoId']);
    }

    /** A intencao entra na MESMA transacao que mudou a OS. */
    public function test_o_prazo_entra_junto_com_a_mudanca_de_estado(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        $this->saga->onOrcamentoGerado($this->evento('orcamento.gerado', $sagaId, [
            'osId' => $osId,
            'orcamentoId' => $this->id('o-1'),
            'valorTotal' => 241.80,
        ]));

        $this->assertSame(
            StatusOrdem::AGUARDANDO_APROVACAO,
            $this->repo->findById($osId)->getStatus()->value()
        );

        $this->assertNotNull(
            collect($this->outboxDe($osId))->firstWhere('destino', 'billing.aguardando-aprovacao')
        );
    }

    /**
     * Aprovar antes do prazo nao desarma a mensagem: ela continua na sala de
     * espera e expira do mesmo jeito, 7 dias depois. Quem protege e a guarda
     * de onAprovacaoExpirada, nao o desarme.
     */
    public function test_expiracao_nao_cancela_os_que_ja_avancou(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        $this->saga->onOrcamentoGerado($this->evento('orcamento.gerado', $sagaId, [
            'osId' => $osId,
            'orcamentoId' => $this->id('o-1'),
            'valorTotal' => 241.80,
        ]));

        $this->saga->onOrcamentoAprovado($this->evento('orcamento.aprovado', $sagaId, [
            'osId' => $osId,
            'orcamentoId' => $this->id('o-1'),
            'decidioEm' => '2026-11-09T10:00:00Z',
        ]));

        $antes = $this->repo->findById($osId)->getStatus()->value();

        // a mensagem da sala de espera chegando depois
        $this->saga->onAprovacaoExpirada($this->evento('aprovacao.expirada', $sagaId, [
            'osId' => $osId,
            'orcamentoId' => $this->id('o-1'),
            'expirouEm' => '2026-11-20T00:00:00Z',
        ]));

        $this->assertSame(
            $antes,
            $this->repo->findById($osId)->getStatus()->value(),
            'expiracao nao pode cancelar OS que ja passou da aprovacao'
        );
    }

    // ── Idempotência ───────────────────────────────────────────────

    public function test_evento_repetido_nao_gera_efeito_nem_segunda_mensagem(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        $evento = $this->evento('orcamento.gerado', $sagaId, [
            'osId' => $osId,
            'orcamentoId' => $this->id('o-1'),
            'valorTotal' => 241.80,
        ]);

        $this->saga->onOrcamentoGerado($evento);
        $this->saga->onOrcamentoGerado($evento); // a mesma mensagem, de novo

        $this->assertSame(
            StatusOrdem::AGUARDANDO_APROVACAO,
            $this->repo->findById($osId)->getStatus()->value()
        );
        $this->assertDatabaseCount('mensagens_processadas', 1);
        // 2 e nao 1: o GerarOrcamento da abertura MAIS o prazo de aprovacao
        // que o onOrcamentoGerado passou a armar na sala de espera (ADR-0010).
        // O que este teste prova continua sendo o mesmo — repetir o evento nao
        // acrescenta NENHUMA linha nova.
        $this->assertCount(2, $this->outboxDe($osId));
    }

    // ── Guardas de ordem ───────────────────────────────────────────

    public function test_evento_incompativel_com_o_estado_e_descartado(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        // pagamento.confirmado em AGUARDANDO_ORCAMENTO: fora de ordem.
        $this->saga->onPagamentoConfirmado($this->evento('pagamento.confirmado', $sagaId, [
            'osId' => $osId,
            'pagamentoId' => $this->id('p-1'),
            'orcamentoId' => $this->id('o-1'),
        ]));

        $this->assertSame(
            StatusOrdem::AGUARDANDO_ORCAMENTO,
            $this->repo->findById($osId)->getStatus()->value()
        );
        // Descartado não é processado: não marca messageId, para um reenvio
        // legítimo depois ainda poder valer.
        $this->assertDatabaseCount('mensagens_processadas', 0);
    }

    public function test_expiracao_de_orcamento_antigo_nao_cancela_a_os(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        $this->saga->onOrcamentoGerado($this->evento('orcamento.gerado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-2'), 'valorTotal' => 300.0,
        ]));

        // Expiração chegando tarde, falando do orçamento anterior.
        $this->saga->onAprovacaoExpirada($this->evento('aprovacao.expirada', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'), 'expirouEm' => '2026-11-01T00:00:00Z',
        ]));

        $this->assertSame(
            StatusOrdem::AGUARDANDO_APROVACAO,
            $this->repo->findById($osId)->getStatus()->value()
        );
    }

    public function test_evento_de_saga_inativa_e_descartado(): void
    {
        [$osId] = $this->osAguardandoOrcamento();

        $this->saga->onOrcamentoGerado($this->evento('orcamento.gerado', (string) \Ramsey\Uuid\Uuid::uuid4(), [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'), 'valorTotal' => 100.0,
        ]));

        $this->assertSame(
            StatusOrdem::AGUARDANDO_ORCAMENTO,
            $this->repo->findById($osId)->getStatus()->value()
        );
    }

    // ── Caminho feliz, ponta a ponta no orquestrador ───────────────

    public function test_caminho_feliz_publica_os_tres_comandos_na_ordem(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        $this->saga->onOrcamentoGerado($this->evento('orcamento.gerado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'), 'valorTotal' => 241.80,
        ]));

        $this->saga->onOrcamentoAprovado($this->evento('orcamento.aprovado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'), 'decidioEm' => '2026-11-09T10:00:00Z',
        ]));

        $this->saga->onPagamentoConfirmado($this->evento('pagamento.confirmado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'), 'pagamentoId' => $this->id('p-1'), 'valorPago' => 241.80,
        ]));

        $this->saga->onExecucaoConcluida($this->evento('execucao.concluida', $sagaId, [
            'osId' => $osId, 'execucaoId' => $this->id('e-1'),
        ]));

        $this->assertSame(
            StatusOrdem::FINALIZADA,
            $this->repo->findById($osId)->getStatus()->value()
        );

        $this->assertSame(
            [
                ['tipo' => 'GerarOrcamento', 'destino' => 'billing.gerar-orcamento', 'canal' => 'comando'],
                // Armado junto com a mudanca para AGUARDANDO_APROVACAO: o
                // broker entrega 7 dias depois, se ninguem aprovar (ADR-0010).
                ['tipo' => 'aprovacao.expirada', 'destino' => 'billing.aguardando-aprovacao', 'canal' => 'comando'],
                ['tipo' => 'CobrarPagamento', 'destino' => 'billing.cobrar-pagamento', 'canal' => 'comando'],
                ['tipo' => 'EnviarParaExecucao', 'destino' => 'execucao.enviar-para-execucao', 'canal' => 'comando'],
                ['tipo' => 'os.finalizada', 'destino' => 'os.finalizada', 'canal' => 'evento'],
            ],
            $this->outboxDe($osId)
        );
    }

    // ── Compensação ────────────────────────────────────────────────

    public function test_falha_na_execucao_compensa_na_ordem_inversa(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        $this->saga->onOrcamentoGerado($this->evento('orcamento.gerado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'), 'valorTotal' => 241.80,
        ]));
        $this->saga->onOrcamentoAprovado($this->evento('orcamento.aprovado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'),
        ]));
        $this->saga->onPagamentoConfirmado($this->evento('pagamento.confirmado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'), 'pagamentoId' => $this->id('p-1'),
        ]));

        $this->saga->onExecucaoFalhou($this->evento('execucao.falhou', $sagaId, [
            'osId' => $osId, 'execucaoId' => $this->id('e-1'), 'motivo' => 'peca_indisponivel',
        ]));

        $this->assertSame(
            StatusOrdem::CANCELADA,
            $this->repo->findById($osId)->getStatus()->value()
        );

        // 4 e nao 3: a ida agora tem GerarOrcamento, o prazo de aprovacao,
        // CobrarPagamento e EnviarParaExecucao. O que vem depois disso e
        // compensacao.
        $compensacoes = array_slice(array_column($this->outboxDe($osId), 'tipo'), 4);

        $this->assertSame(
            ['CancelarExecucao', 'EstornarPagamento', 'AnularOrcamento', 'os.cancelada'],
            $compensacoes,
            'a compensação desfaz na ordem inversa dos passos'
        );
    }

    public function test_pagamento_recusado_anula_orcamento_e_nao_estorna(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        $this->saga->onOrcamentoGerado($this->evento('orcamento.gerado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'), 'valorTotal' => 241.80,
        ]));
        $this->saga->onOrcamentoAprovado($this->evento('orcamento.aprovado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'),
        ]));

        $this->saga->onPagamentoRecusado($this->evento('pagamento.recusado', $sagaId, [
            'osId' => $osId, 'orcamentoId' => $this->id('o-1'), 'motivo' => 'cc_rejected',
        ]));

        $this->assertSame(
            StatusOrdem::CANCELADA,
            $this->repo->findById($osId)->getStatus()->value()
        );

        $tipos = array_column($this->outboxDe($osId), 'tipo');

        $this->assertContains('AnularOrcamento', $tipos);
        $this->assertNotContains(
            'EstornarPagamento',
            $tipos,
            'não houve pagamento confirmado: não há o que estornar'
        );
    }

    public function test_orcamento_falhou_cancela_sem_compensar_nada(): void
    {
        [$osId, $sagaId] = $this->osAguardandoOrcamento();

        $this->saga->onOrcamentoFalhou($this->evento('orcamento.falhou', $sagaId, [
            'osId' => $osId, 'motivo' => 'sem_itens_a_orcar', 'ocorridoEm' => '2026-11-09T10:00:00Z',
        ]));

        $this->assertSame(
            StatusOrdem::CANCELADA,
            $this->repo->findById($osId)->getStatus()->value()
        );

        $tipos = array_column($this->outboxDe($osId), 'tipo');

        // Nenhum orçamento chegou a existir: só o anúncio.
        $this->assertSame(['GerarOrcamento', 'os.cancelada'], $tipos);
    }

    public function test_saga_sem_itens_diagnosticados_nao_inicia(): void
    {
        $os = OrdemServico::criar($this->uuid(), $this->uuid(), 'x');
        $os->iniciarDiagnostico($this->uuid());
        $os->setDiagnostico('Nada a reparar');
        $this->repo->save($os);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('sem_itens_a_orcar');

        $this->saga->iniciarSaga($os->getId(), []);
    }
}
