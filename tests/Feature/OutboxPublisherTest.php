<?php

namespace Tests\Feature;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessagePublisher;
use App\Application\Messaging\TopologiaAusente;
use App\Application\Saga\Outbox;
use App\Application\Saga\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Ramsey\Uuid\Uuid;
use Tests\ExigeBancoDeTeste;
use Tests\TestCase;

/**
 * O lado de leitura do outbox: o que sai para o broker e o que fica na tabela.
 *
 * O SagaOutboxTest prova que a intencao ENTRA junto com a transicao. Este prova
 * que ela SAI — e, principalmente, o que acontece quando nao sai:
 *
 *   publica antes de marcar     -> queda no meio duplica, nunca perde
 *   falhou                      -> conta tentativa, guarda o erro, tenta de novo
 *   passou do teto              -> para de tentar e aparece em travadas()
 *
 * Banco de verdade, porque o que se testa e a tabela saga_outbox (jsonb, uuid,
 * indice parcial). O broker e dublado: o contrato com ele ja esta no
 * MessagePublisherTest.
 */
class OutboxPublisherTest extends TestCase
{
    use ExigeBancoDeTeste;
    use RefreshDatabase;

    private MockInterface $broker;
    private OutboxPublisher $publicador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exigirBancoDeTeste();

        $this->broker = Mockery::mock(MessagePublisher::class);
        $this->publicador = new OutboxPublisher($this->broker);
    }

    private function uuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    /** Grava como o orquestrador grava: pelo Outbox, dentro de transacao. */
    private function comando(string $fila, string $tipo, ?string $criadoEm = null): Envelope
    {
        $env = Envelope::create($tipo, ['osId' => $osId = $this->uuid()], $this->uuid());

        DB::transaction(fn () => app(Outbox::class)->comando($fila, $env, $osId));

        if ($criadoEm !== null) {
            $this->linha($env)->update(['criado_em' => $criadoEm]);
        }

        return $env;
    }

    private function evento(string $tipo): Envelope
    {
        $env = Envelope::create($tipo, ['osId' => $osId = $this->uuid()], $this->uuid());

        DB::transaction(fn () => app(Outbox::class)->evento($env, $osId));

        return $env;
    }

    private function linha(Envelope $env): \Illuminate\Database\Query\Builder
    {
        return DB::table('saga_outbox')->where('message_id', $env->getMessageId());
    }

    private function mesmoEnvelope(Envelope $esperado): \Mockery\Matcher\Closure
    {
        return Mockery::on(fn (Envelope $e) => $e->getMessageId() === $esperado->getMessageId()
            && $e->getType() === $esperado->getType()
            && $e->getSagaId() === $esperado->getSagaId()
            && $e->getBody() === $esperado->getBody());
    }

    // ── Caminho feliz ──────────────────────────────────────────────

    public function test_comando_vai_para_a_fila_e_evento_para_o_exchange_e_os_dois_ficam_marcados(): void
    {
        $cmd = $this->comando('billing.gerar-orcamento', 'GerarOrcamento');
        $evt = $this->evento('os.cancelada');

        $this->broker->shouldReceive('publishCommand')->once()
            ->with('billing.gerar-orcamento', $this->mesmoEnvelope($cmd));
        $this->broker->shouldReceive('publishEvent')->once()
            ->with($this->mesmoEnvelope($evt));

        $this->assertSame(2, $this->publicador->publicarPendentes());

        foreach ([$cmd, $evt] as $env) {
            $linha = $this->linha($env)->first();
            $this->assertNotNull($linha->publicado_em, "{$env->getType()} devia estar marcado como publicado");
            $this->assertNull($linha->ultimo_erro);
            $this->assertSame(0, (int) $linha->tentativas);
        }
    }

    public function test_linha_ja_publicada_nao_sai_de_novo(): void
    {
        $env = $this->comando('billing.gerar-orcamento', 'GerarOrcamento');
        $this->broker->shouldReceive('publishCommand')->once();

        $this->assertSame(1, $this->publicador->publicarPendentes());
        $this->assertSame(0, $this->publicador->publicarPendentes(), 'segunda passada nao tem nada pendente');
        $this->assertNotNull($this->linha($env)->value('publicado_em'));
    }

    public function test_publica_na_ordem_de_criacao_e_respeita_o_limite(): void
    {
        $terceiro = $this->comando('billing.cobrar-pagamento', 'CobrarPagamento', '2026-11-15 10:00:03');
        $primeiro = $this->comando('billing.gerar-orcamento', 'GerarOrcamento', '2026-11-15 10:00:01');
        $segundo = $this->comando('execucao.enviar-para-execucao', 'EnviarParaExecucao', '2026-11-15 10:00:02');

        $ordem = [];
        $this->broker->shouldReceive('publishCommand')->twice()
            ->andReturnUsing(function (string $fila) use (&$ordem): void {
                $ordem[] = $fila;
            });

        $this->assertSame(2, $this->publicador->publicarPendentes(limite: 2));

        $this->assertSame(['billing.gerar-orcamento', 'execucao.enviar-para-execucao'], $ordem);
        $this->assertNotNull($this->linha($primeiro)->value('publicado_em'));
        $this->assertNotNull($this->linha($segundo)->value('publicado_em'));
        $this->assertNull($this->linha($terceiro)->value('publicado_em'), 'o terceiro fica para a proxima passada');
    }

    // ── Falha ──────────────────────────────────────────────────────

    public function test_falha_ao_publicar_conta_tentativa_guarda_o_erro_e_nao_marca(): void
    {
        Log::spy();

        $env = $this->comando('billing.gerar-orcamento', 'GerarOrcamento');
        $this->broker->shouldReceive('publishCommand')->once()
            ->andThrow(new TopologiaAusente('Fila billing.gerar-orcamento nao existe neste broker.'));

        $this->assertSame(0, $this->publicador->publicarPendentes());

        $linha = $this->linha($env)->first();
        $this->assertNull($linha->publicado_em, 'falhou, entao NAO pode estar marcada: senao a mensagem se perde');
        $this->assertSame(1, (int) $linha->tentativas);
        $this->assertSame('Fila billing.gerar-orcamento nao existe neste broker.', $linha->ultimo_erro);

        Log::shouldHaveReceived('warning')->with('Outbox: falha ao publicar, vai tentar de novo', Mockery::on(
            fn (array $ctx) => $ctx['messageId'] === $env->getMessageId() && $ctx['tentativas'] === 1
        ))->once();
    }

    public function test_falha_numa_linha_nao_impede_as_outras(): void
    {
        $ruim = $this->comando('billing.gerar-orcamento', 'GerarOrcamento', '2026-11-15 10:00:01');
        $boa = $this->evento('os.cancelada');

        $this->broker->shouldReceive('publishCommand')->once()->andThrow(new \RuntimeException('broker fora'));
        $this->broker->shouldReceive('publishEvent')->once();

        $this->assertSame(1, $this->publicador->publicarPendentes());
        $this->assertNull($this->linha($ruim)->value('publicado_em'));
        $this->assertNotNull($this->linha($boa)->value('publicado_em'));
    }

    public function test_sucesso_depois_de_falha_limpa_o_erro(): void
    {
        $env = $this->comando('billing.gerar-orcamento', 'GerarOrcamento');

        $this->broker->shouldReceive('publishCommand')->once()->andThrow(new \RuntimeException('broker fora'));
        $this->publicador->publicarPendentes();
        $this->assertSame('broker fora', $this->linha($env)->value('ultimo_erro'));

        $this->broker->shouldReceive('publishCommand')->once();
        $this->assertSame(1, $this->publicador->publicarPendentes());

        $linha = $this->linha($env)->first();
        $this->assertNotNull($linha->publicado_em);
        $this->assertNull($linha->ultimo_erro);
    }

    public function test_erro_muito_longo_e_truncado_em_mil_caracteres(): void
    {
        $env = $this->comando('billing.gerar-orcamento', 'GerarOrcamento');
        $this->broker->shouldReceive('publishCommand')->andThrow(new \RuntimeException(str_repeat('x', 5000)));

        $this->publicador->publicarPendentes();

        $this->assertSame(1000, mb_strlen($this->linha($env)->value('ultimo_erro')));
    }

    // ── Teto de tentativas ─────────────────────────────────────────

    public function test_no_teto_de_tentativas_desiste_alerta_e_a_linha_aparece_em_travadas(): void
    {
        Log::spy();

        $env = $this->comando('billing.gerar-orcamento', 'GerarOrcamento');
        $this->linha($env)->update(['tentativas' => OutboxPublisher::MAX_TENTATIVAS - 1]);

        $this->broker->shouldReceive('publishCommand')->once()->andThrow(new \RuntimeException('broker fora'));

        $this->assertSame([], $this->publicador->travadas()->all());

        $this->publicador->publicarPendentes();

        $this->assertSame(OutboxPublisher::MAX_TENTATIVAS, (int) $this->linha($env)->value('tentativas'));
        Log::shouldHaveReceived('error')->with('Outbox: desistiu apos o limite de tentativas', Mockery::on(
            fn (array $ctx) => $ctx['messageId'] === $env->getMessageId() && $ctx['tipo'] === 'GerarOrcamento'
        ))->once();

        $travadas = $this->publicador->travadas();
        $this->assertCount(1, $travadas);
        $this->assertSame($env->getMessageId(), $travadas->first()->message_id);
        $this->assertSame('broker fora', $travadas->first()->ultimo_erro);
    }

    public function test_linha_travada_nao_e_mais_tentada(): void
    {
        $env = $this->comando('billing.gerar-orcamento', 'GerarOrcamento');
        $this->linha($env)->update(['tentativas' => OutboxPublisher::MAX_TENTATIVAS]);

        $this->broker->shouldNotReceive('publishCommand');

        $this->assertSame(0, $this->publicador->publicarPendentes());
        $this->assertCount(1, $this->publicador->travadas());
    }

    public function test_linha_publicada_nao_conta_como_travada_mesmo_com_tentativas_altas(): void
    {
        $env = $this->comando('billing.gerar-orcamento', 'GerarOrcamento');
        $this->linha($env)->update([
            'tentativas' => OutboxPublisher::MAX_TENTATIVAS,
            'publicado_em' => now(),
        ]);

        $this->assertCount(0, $this->publicador->travadas());
    }
}
