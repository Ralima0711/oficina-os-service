<?php

namespace Tests\Unit\Observability;

use App\Application\Messaging\Envelope;
use App\Infrastructure\Messaging\MessageTracing;
use App\Infrastructure\Observability\OpenTelemetryProvider;
use App\Infrastructure\Observability\Tracing;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\TestCase;

/**
 * O quality gate do Sonar reprovou o PR #17 com 54,9% de cobertura no código
 * novo. O que faltava eram justamente os caminhos de erro e o provider OTLP:
 * o span feliz já estava provado, mas ninguém exercitava exportador, headers,
 * exceção dentro do span nem o envelope reescrito.
 */
class TracingCaminhosTest extends TestCase
{
    private const TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    protected function tearDown(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('OTEL_EXPORTER_OTLP_HEADERS');
        unset(
            $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'],
            $_ENV['OTEL_EXPORTER_OTLP_HEADERS'],
            $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT'],
            $_SERVER['OTEL_EXPORTER_OTLP_HEADERS'],
        );

        OpenTelemetryProvider::reiniciar();

        parent::tearDown();
    }

    // ── Provider OTLP ──────────────────────────────────────────────

    public function test_com_endpoint_o_provider_cria_exportador_otlp(): void
    {
        $this->comAmbiente('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://127.0.0.1:4318');

        $provider = OpenTelemetryProvider::provider();

        $this->assertNotNull($provider->getTracer('oficina-os-service'));
        $this->assertSame($provider, OpenTelemetryProvider::provider(), 'o provider é memoizado');
    }

    public function test_endpoint_invalido_desliga_a_exportacao_sem_derrubar_o_servico(): void
    {
        // URL sem esquema: o transporte do OTLP recusa, e o serviço tem que
        // seguir sem exportar em vez de estourar na primeira requisição.
        $this->comAmbiente('OTEL_EXPORTER_OTLP_ENDPOINT', 'nao-e-uma-url');

        $span = Tracing::iniciar('teste.endpoint.invalido');

        $this->assertNotNull($span);

        Tracing::finalizar($span);
    }

    public function test_headers_do_otlp_sao_parseados_em_pares(): void
    {
        $headers = $this->invocar('parseHeaders', 'api-key=abc123, outro=yyy ,invalido,, =vazio');

        $this->assertSame('abc123', $headers['api-key']);
        $this->assertSame('yyy', $headers['outro']);
        $this->assertArrayNotHasKey('invalido', $headers);
    }

    public function test_recurso_carrega_service_name_e_ambiente(): void
    {
        $this->comAmbiente('OTEL_SERVICE_NAME', 'oficina-os-service-teste');

        $recurso = $this->invocar('recurso');

        $this->assertSame(
            'oficina-os-service-teste',
            $recurso->getAttributes()->get('service.name')
        );
        $this->assertNotNull($recurso->getAttributes()->get('service.version'));
    }

    public function test_reiniciar_descarta_o_provider_memoizado(): void
    {
        $primeiro = OpenTelemetryProvider::provider();

        OpenTelemetryProvider::reiniciar();

        $this->assertNotSame($primeiro, OpenTelemetryProvider::provider());
    }

    // ── Tracing ────────────────────────────────────────────────────

    public function test_executar_devolve_o_resultado_e_fecha_o_span(): void
    {
        $resultado = Tracing::executar('teste.executar', ['os.id' => 'os-1'], fn () => 'ok');

        $this->assertSame('ok', $resultado);
        $this->assertNull(Tracing::traceIdAtual(), 'o span foi encerrado e desativado');
    }

    public function test_executar_marca_erro_e_repropaga_a_excecao(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('falhou dentro do span');

        try {
            Tracing::executar('teste.executar.erro', [], function (): void {
                throw new \RuntimeException('falhou dentro do span');
            });
        } finally {
            $this->assertNull(Tracing::traceIdAtual(), 'o finally encerra o span mesmo no erro');
        }
    }

    public function test_atributo_nulo_e_descartado_sem_quebrar(): void
    {
        $span = Tracing::iniciar('teste.atributo.nulo', ['os.id' => null, 'outro' => 'x']);

        $this->assertNotNull($span);

        Tracing::finalizar($span);
    }

    public function test_span_nulo_e_aceito_em_sucesso_erro_e_finalizacao(): void
    {
        Tracing::sucesso(null);
        Tracing::erro(null, new \RuntimeException('sem span'));
        Tracing::finalizar(null);

        $this->assertNull(Tracing::traceIdAtual());
    }

    public function test_span_aninhado_vira_filho_do_corrente(): void
    {
        $pai = Tracing::iniciar('teste.pai');
        $traceDoPai = Tracing::traceIdAtual();

        $filho = Tracing::iniciar('teste.filho');

        $this->assertSame($traceDoPai, Tracing::traceIdAtual(), 'o filho herda o trace do pai');

        Tracing::finalizar($filho);
        Tracing::finalizar($pai);
    }

    public function test_span_com_pai_explicito_usa_o_trace_do_pai(): void
    {
        $span = Tracing::iniciar(
            'teste.pai.explicito',
            [],
            SpanKind::KIND_CONSUMER,
            \App\Infrastructure\Messaging\TraceContextCarrier::extract(['traceparent' => self::TRACEPARENT]),
        );

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', Tracing::traceIdAtual());

        Tracing::finalizar($span);
    }

    // ── MessageTracing ─────────────────────────────────────────────

    public function test_publicar_entrega_headers_com_traceparent(): void
    {
        $recebidos = null;

        $resultado = MessageTracing::publicar(
            'billing.gerar-orcamento',
            function (array $headers) use (&$recebidos) {
                $recebidos = $headers;

                return 'publicado';
            },
            ['os.id' => 'os-1'],
        );

        $this->assertSame('publicado', $resultado);
        $this->assertArrayHasKey('traceparent', $recebidos);
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/', $recebidos['traceparent']);
    }

    public function test_publicar_marca_erro_e_repropaga(): void
    {
        $this->expectException(\RuntimeException::class);

        MessageTracing::publicar('billing.gerar-orcamento', function (): void {
            throw new \RuntimeException('broker fora');
        });
    }

    public function test_consumir_herda_o_trace_da_mensagem(): void
    {
        $traceVisto = MessageTracing::consumir(
            'os.saga.eventos',
            ['traceparent' => self::TRACEPARENT],
            fn () => Tracing::traceIdAtual(),
        );

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $traceVisto);
    }

    public function test_consumir_marca_erro_e_repropaga(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handler falhou');

        MessageTracing::consumir('os.saga.eventos', [], function (): void {
            throw new \RuntimeException('handler falhou');
        });
    }

    // ── Envelope ───────────────────────────────────────────────────

    public function test_envelope_com_traceparent_diferente_devolve_copia(): void
    {
        $original = Envelope::create('os.cancelada', ['osId' => 'os-1'], 'saga-1');

        $novo = $original->comTraceparent(self::TRACEPARENT);

        $this->assertNotSame($original, $novo);
        $this->assertSame(self::TRACEPARENT, $novo->getTraceparent());
        $this->assertSame($original->getMessageId(), $novo->getMessageId(), 'o resto do envelope não muda');
    }

    public function test_envelope_com_traceparent_igual_ou_vazio_devolve_o_mesmo(): void
    {
        $original = Envelope::create('os.cancelada', ['osId' => 'os-1'], 'saga-1', traceparent: self::TRACEPARENT);

        $this->assertSame($original, $original->comTraceparent(self::TRACEPARENT));
        $this->assertSame($original, $original->comTraceparent(''));
    }

    // ── Ajudantes ──────────────────────────────────────────────────

    private function comAmbiente(string $chave, string $valor): void
    {
        putenv("{$chave}={$valor}");
        $_ENV[$chave] = $valor;
    }

    private function invocar(string $metodo, mixed ...$argumentos): mixed
    {
        $reflexao = new \ReflectionMethod(OpenTelemetryProvider::class, $metodo);

        return $reflexao->invoke(null, ...$argumentos);
    }
}
