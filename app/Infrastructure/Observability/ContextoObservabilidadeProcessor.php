<?php

namespace App\Infrastructure\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

/**
 * CAMADA DE INFRAESTRUTURA — Observabilidade
 *
 * Injeta service.name e o trace.id do span corrente em cada linha de log. É o
 * que faz o "logs in context" do New Relic funcionar: sem isso o log sai em
 * texto livre e não se liga a trace nenhum.
 */
class ContextoObservabilidadeProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $contexto = $record->context;

        $contexto['service.name'] ??= OpenTelemetryProvider::nomeServico();

        $span = Span::getCurrent()->getContext();
        if ($span->isValid()) {
            $contexto['trace.id'] ??= $span->getTraceId();
            $contexto['span.id'] ??= $span->getSpanId();
        }

        return $record->with(context: $contexto);
    }
}
