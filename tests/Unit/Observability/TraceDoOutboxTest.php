<?php

namespace Tests\Unit\Observability;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessagePublisher;
use App\Infrastructure\Messaging\MessageTracing;
use App\Infrastructure\Observability\OpenTelemetryProvider;
use PHPUnit\Framework\TestCase;

/**
 * O publicador do outbox roda em outro processo (saga:publicar-outbox), sem span
 * corrente. Sem pai explícito, o span PRODUCER virava raiz de um trace novo e a
 * saga se partia em um trace por mensagem publicada.
 */
class TraceDoOutboxTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-00f067aa0ba902b7-01';

    protected function tearDown(): void
    {
        OpenTelemetryProvider::reiniciar();
        parent::tearDown();
    }

    public function test_publicacao_do_outbox_continua_o_trace_guardado_no_envelope(): void
    {
        $envelope = Envelope::create(
            type: 'GerarOrcamento',
            body: ['osId' => 'os-1'],
            sagaId: '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f',
            traceparent: self::TRACEPARENT,
        );

        $publicado = null;
        MessageTracing::publicar(
            'billing.gerar-orcamento',
            function (array $headers) use (&$publicado): void {
                $publicado = $headers['traceparent'] ?? null;
            },
            [],
            MessagePublisher::contextoPai($envelope),
        );

        $this->assertNotNull($publicado, 'A publicação precisa levar traceparent.');
        $this->assertSame(self::TRACE_ID, explode('-', $publicado)[1], 'A mensagem publicada tem que continuar o trace da saga.');
    }

    public function test_envelope_sem_traceparent_valido_nao_quebra_a_publicacao(): void
    {
        $envelope = Envelope::create(type: 'GerarOrcamento', body: ['osId' => 'os-1'], sagaId: '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f')
            ->comTraceparent('lixo');

        $chamado = false;
        MessageTracing::publicar('billing.gerar-orcamento', function () use (&$chamado): void {
            $chamado = true;
        }, [], MessagePublisher::contextoPai($envelope));

        $this->assertTrue($chamado);
    }
}
