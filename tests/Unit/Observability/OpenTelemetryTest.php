<?php

namespace Tests\Unit\Observability;

use App\Infrastructure\Messaging\TraceContextCarrier;
use App\Infrastructure\Observability\ContextoObservabilidadeProcessor;
use App\Infrastructure\Observability\OpenTelemetryProvider;
use App\Infrastructure\Observability\Tracing;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * O que estes testes provam: o os-service gera span e propaga contexto, em vez
 * de só repassar o traceparent de terceiros. Sem exportador, o que se afirma é
 * o contexto — trace id, span pai, service.name — que é justamente o que
 * faltava.
 */
class OpenTelemetryTest extends TestCase
{
    private const TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    protected function tearDown(): void
    {
        OpenTelemetryProvider::reiniciar();
        parent::tearDown();
    }

    public function test_sem_endpoint_o_provider_nao_exporta_e_nao_quebra(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        unset($_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'], $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT']);

        OpenTelemetryProvider::reiniciar();

        $span = Tracing::iniciar('teste.noop');

        $this->assertNotNull($span, 'span deve existir mesmo sem exportador');

        Tracing::finalizar($span);
    }

    public function test_nome_do_servico_vem_do_ambiente_com_padrao_do_os_service(): void
    {
        $this->assertSame('oficina-os-service', OpenTelemetryProvider::nomeServico());

        putenv('OTEL_SERVICE_NAME=oficina-os-service-teste');
        $_ENV['OTEL_SERVICE_NAME'] = 'oficina-os-service-teste';

        $this->assertSame('oficina-os-service-teste', OpenTelemetryProvider::nomeServico());

        putenv('OTEL_SERVICE_NAME');
        unset($_ENV['OTEL_SERVICE_NAME']);
    }

    public function test_carrier_faz_roundtrip_do_traceparent(): void
    {
        // inject só acrescenta header quando existe span corrente: é o span que
        // carrega o contexto. Fora de span, os headers voltam como vieram.
        $span = Tracing::iniciar('teste.inject');

        $headers = TraceContextCarrier::inject(['outro' => 'valor']);

        Tracing::finalizar($span);

        $this->assertArrayHasKey('traceparent', $headers);
        $this->assertSame('valor', $headers['outro']);

        $contexto = TraceContextCarrier::extract(['traceparent' => self::TRACEPARENT]);

        $this->assertTrue(\OpenTelemetry\API\Trace\Span::fromContext($contexto)->getContext()->isValid());
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', \OpenTelemetry\API\Trace\Span::fromContext($contexto)->getContext()->getTraceId());
    }

    public function test_carrier_sem_span_nao_inventa_traceparent(): void
    {
        $this->assertSame(['outro' => 'valor'], TraceContextCarrier::inject(['outro' => 'valor']));
    }

    public function test_carrier_ignora_chave_em_maiuscula_diferente(): void
    {
        $contexto = TraceContextCarrier::extract(['TraceParent' => self::TRACEPARENT]);

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', \OpenTelemetry\API\Trace\Span::fromContext($contexto)->getContext()->getTraceId());
    }

    public function test_span_consumidor_herda_o_trace_da_mensagem(): void
    {
        $span = Tracing::iniciar(
            'os.saga.eventos process',
            [],
            \OpenTelemetry\API\Trace\SpanKind::KIND_CONSUMER,
            TraceContextCarrier::extract(['traceparent' => self::TRACEPARENT]),
        );

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', Tracing::traceIdAtual());

        Tracing::finalizar($span);

        $this->assertNull(Tracing::traceIdAtual(), 'fora de span não há trace corrente');
    }

    public function test_processor_injeta_service_name_e_trace_id(): void
    {
        $span = Tracing::iniciar(
            'teste.log',
            [],
            \OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL,
            TraceContextCarrier::extract(['traceparent' => self::TRACEPARENT]),
        );

        $registro = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'stdout_json',
            level: Level::Info,
            message: 'Saga: iniciada',
            context: ['osId' => 'os-1'],
            extra: [],
        );

        $processado = (new ContextoObservabilidadeProcessor())($registro);

        $this->assertSame('oficina-os-service', $processado->context['service.name']);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $processado->context['trace.id']);
        $this->assertArrayHasKey('span.id', $processado->context);
        $this->assertSame('os-1', $processado->context['osId']);

        Tracing::finalizar($span);
    }
}
