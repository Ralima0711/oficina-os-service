<?php

namespace App\Infrastructure\Observability;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * CAMADA DE INFRAESTRUTURA — Observabilidade
 *
 * Provider OTLP/HTTP do OpenTelemetry. Mesmo desenho do Billing e da Execução:
 * sem endpoint configurado o provider é no-op e nada quebra; com endpoint, os
 * spans vão para o coletor (New Relic, no cluster).
 *
 * O infra já entrega OTEL_EXPORTER_OTLP_ENDPOINT e
 * OTEL_SERVICE_NAME=oficina-os-service nos manifestos (PR #9 do
 * oficina-infra-fase4). Antes deste arquivo, o os-service repassava o
 * traceparent no envelope sem gerar span nenhum: no New Relic aparecia só o
 * pedaço da Execução e o trace não atravessava os três serviços.
 */
class OpenTelemetryProvider
{
    private const SERVICO_PADRAO = 'oficina-os-service';

    private static ?TracerProviderInterface $provider = null;

    private static bool $shutdownRegistrado = false;

    public static function nomeServico(): string
    {
        return (string) (env('OTEL_SERVICE_NAME') ?: self::SERVICO_PADRAO);
    }

    public static function tracer(): TracerInterface
    {
        return self::provider()->getTracer(self::nomeServico());
    }

    public static function provider(): TracerProviderInterface
    {
        if (self::$provider !== null) {
            return self::$provider;
        }

        $endpoint = (string) (env('OTEL_EXPORTER_OTLP_ENDPOINT') ?: '');

        if ($endpoint === '') {
            // Sem endpoint configurado: provider no-op (não exporta, não quebra).
            self::$provider = new TracerProvider([], new AlwaysOnSampler());

            return self::$provider;
        }

        try {
            self::$provider = self::criarProviderOtlp($endpoint);
        } catch (\Throwable $e) {
            // Observabilidade mal configurada (ex.: URL inválida) nunca derruba o serviço:
            // segue sem exportar, e o motivo fica no log do processo.
            error_log('[otel] exportação desativada, OTEL_EXPORTER_OTLP_ENDPOINT inválido: '.$e->getMessage());
            self::$provider = new TracerProvider([], new AlwaysOnSampler());

            return self::$provider;
        }

        // O BatchSpanProcessor enfileira os spans e só os envia no flush/shutdown.
        // Em PHP-FPM o processo morre ao fim da requisição, e o SDK não registra
        // esse encerramento sozinho — sem isto, a fila morre com o processo e
        // nenhum trace chega ao coletor.
        self::registrarShutdown();

        return self::$provider;
    }

    /** Só para teste: descarta o provider memoizado entre cenários. */
    public static function reiniciar(): void
    {
        self::$provider = null;
        self::$shutdownRegistrado = false;
    }

    private static function criarProviderOtlp(string $endpoint): TracerProviderInterface
    {
        $headers = self::parseHeaders((string) (env('OTEL_EXPORTER_OTLP_HEADERS') ?: ''));

        $transport = (new OtlpHttpTransportFactory())->create(
            $endpoint.'/v1/traces',
            'application/x-protobuf',
            $headers
        );

        $exporter = new SpanExporter($transport);

        $processor = new BatchSpanProcessor(
            $exporter,
            ClockFactory::getDefault(),
            BatchSpanProcessor::DEFAULT_MAX_QUEUE_SIZE,
            BatchSpanProcessor::DEFAULT_SCHEDULE_DELAY,
            BatchSpanProcessor::DEFAULT_EXPORT_TIMEOUT,
            BatchSpanProcessor::DEFAULT_MAX_EXPORT_BATCH_SIZE,
        );

        return new TracerProvider([$processor], new AlwaysOnSampler(), self::recurso());
    }

    private static function recurso(): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => self::nomeServico(),
                ResourceAttributes::SERVICE_VERSION => (string) (env('APP_VERSION') ?: '1.0.0'),
                ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => (string) (env('APP_ENV') ?: 'production'),
            ]))
        );
    }

    /**
     * OTEL_EXPORTER_OTLP_HEADERS vem no formato "api-key=xxx,outro=yyy".
     *
     * @return array<string, string>
     */
    private static function parseHeaders(string $bruto): array
    {
        $headers = [];

        foreach (explode(',', $bruto) as $par) {
            $par = trim($par);
            if ($par === '' || !str_contains($par, '=')) {
                continue;
            }

            [$chave, $valor] = explode('=', $par, 2);
            $headers[trim($chave)] = trim($valor);
        }

        return $headers;
    }

    private static function registrarShutdown(): void
    {
        if (self::$shutdownRegistrado) {
            return;
        }

        self::$shutdownRegistrado = true;

        register_shutdown_function(static function (): void {
            try {
                self::$provider?->shutdown();
            } catch (\Throwable) {
                // Encerramento nunca pode virar erro fatal no fim da requisição.
            }
        });
    }
}
