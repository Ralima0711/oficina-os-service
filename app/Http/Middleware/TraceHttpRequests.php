<?php

namespace App\Http\Middleware;

use App\Infrastructure\Messaging\TraceContextCarrier;
use App\Infrastructure\Observability\Tracing;
use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\SpanKind;
use Symfony\Component\HttpFoundation\Response;

/**
 * Span SERVER por requisição HTTP.
 *
 * É o que dá origem ao trace quando a chamada entra pela borda: sem ele, o
 * traceparent que o Kong repassa não vira span nenhum e o trace começa no
 * primeiro evento publicado, não na requisição do cliente.
 *
 * O contexto de entrada vem dos headers HTTP (W3C), então uma chamada que já
 * traz traceparent continua o trace de quem chamou.
 */
class TraceHttpRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $span = Tracing::iniciar(
            $request->getMethod().' '.$request->path(),
            [
                'http.request.method' => $request->getMethod(),
                'url.path' => '/'.$request->path(),
                'url.scheme' => $request->getScheme(),
                'server.address' => $request->getHost(),
            ],
            SpanKind::KIND_SERVER,
            TraceContextCarrier::extract($request->headers->all()),
        );

        try {
            $response = $next($request);

            $span?->setAttribute('http.response.status_code', $response->getStatusCode());

            if ($response->getStatusCode() >= 500) {
                Tracing::erro($span, new \RuntimeException('HTTP '.$response->getStatusCode()));
            } else {
                Tracing::sucesso($span);
            }

            return $response;
        } catch (\Throwable $e) {
            Tracing::erro($span, $e);

            throw $e;
        } finally {
            Tracing::finalizar($span);
        }
    }
}
