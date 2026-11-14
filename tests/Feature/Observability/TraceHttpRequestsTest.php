<?php

namespace Tests\Feature\Observability;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * O middleware HTTP é o que faz o trace nascer na borda. Sem ele, o
 * traceparent que o Kong repassa não vira span e o trace começa no primeiro
 * evento publicado, não na requisição do cliente.
 */
class TraceHttpRequestsTest extends TestCase
{
    private const TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    public function test_requisicao_com_traceparent_continua_o_trace(): void
    {
        Route::get('/api/_teste/trace', fn () => response()->json([
            'traceId' => \App\Infrastructure\Observability\Tracing::traceIdAtual(),
        ]));

        $resposta = $this->getJson('/api/_teste/trace', ['traceparent' => self::TRACEPARENT]);

        $resposta->assertOk();
        $this->assertSame(
            '4bf92f3577b34da6a3ce929d0e0e4736',
            $resposta->json('traceId'),
            'o span do middleware deve herdar o trace de quem chamou'
        );
    }

    public function test_requisicao_sem_traceparent_gera_trace_novo(): void
    {
        Route::get('/api/_teste/trace-novo', fn () => response()->json([
            'traceId' => \App\Infrastructure\Observability\Tracing::traceIdAtual(),
        ]));

        $resposta = $this->getJson('/api/_teste/trace-novo');

        $resposta->assertOk();
        $this->assertNotNull($resposta->json('traceId'));
        $this->assertNotSame('4bf92f3577b34da6a3ce929d0e0e4736', $resposta->json('traceId'));
    }

    public function test_erro_500_marca_o_span_e_nao_engole_a_excecao(): void
    {
        Route::get('/api/_teste/erro', fn () => throw new \RuntimeException('falhou'));

        $this->getJson('/api/_teste/erro')->assertStatus(500);
    }
}
