<?php

namespace Tests\Unit\Messaging;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessagePublisher;
use App\Application\Messaging\TopologiaAusente;
use App\Infrastructure\Observability\OpenTelemetryProvider;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use ReflectionProperty;
use Tests\TestCase;

/**
 * O que o MessagePublisher promete ao outbox, sem broker de verdade.
 *
 * A conexao e dublada e injetada na propriedade privada: o publicador so abre
 * uma AMQPStreamConnection nova quando nao ha conexao viva, entao com o duble
 * no lugar todo o resto do caminho e o codigo de producao, sem desvio.
 *
 * As tres coisas que ja quebraram ou quebrariam em silencio:
 *
 *   - declare ATIVO de fila com DLX => PRECONDITION_FAILED 406 em todo comando;
 *     aqui se trava que o declare e PASSIVO (segundo argumento true);
 *   - fila/exchange inexistente => no exchange default a mensagem some sem
 *     erro; aqui se trava que vira TopologiaAusente e nada e publicado;
 *   - messageId/traceparent fora do header AMQP => o consumidor nao deduplica
 *     nem continua o trace.
 */
class MessagePublisherTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const TRACEPARENT = '00-' . self::TRACE_ID . '-00f067aa0ba902b7-01';

    private MockInterface $conexao;
    private MockInterface $canal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->canal = Mockery::mock(AMQPChannel::class);
        $this->conexao = Mockery::mock(AMQPStreamConnection::class);
        $this->conexao->shouldReceive('isConnected')->andReturn(true)->byDefault();
        $this->conexao->shouldReceive('channel')->andReturn($this->canal)->byDefault();
    }

    protected function tearDown(): void
    {
        OpenTelemetryProvider::reiniciar();
        parent::tearDown();
    }

    private function publicador(string $exchange = 'oficina.eventos'): MessagePublisher
    {
        $publicador = new MessagePublisher('nao-usado', 5672, 'guest', 'guest', '/', $exchange);

        $prop = new ReflectionProperty(MessagePublisher::class, 'connection');
        $prop->setAccessible(true);
        $prop->setValue($publicador, $this->conexao);

        return $publicador;
    }

    private function envelope(string $tipo = 'GerarOrcamento'): Envelope
    {
        return Envelope::create(
            type: $tipo,
            body: ['osId' => 'os-1', 'itens' => [['descricao' => 'Filtro']]],
            sagaId: '9b2f4c1d-3e5a-4f6b-8c7d-0a1b2c3d4e5f',
            causationId: 'causa-1',
            traceparent: self::TRACEPARENT,
        );
    }

    /**
     * A excecao que o php-amqplib lanca quando o declare passivo nao acha a
     * fila/exchange. O construtor dela le a tabela de constantes do protocolo,
     * que o canal real preenche ao nascer — aqui o canal e duble, entao a
     * tabela do AMQP 0-9-1 (a que o RabbitMQ fala) e posta antes.
     */
    private function topologiaAusente(): AMQPProtocolChannelException
    {
        AbstractChannel::$PROTOCOL_CONSTANTS_CLASS ??= \PhpAmqpLib\Wire\Constants091::class;

        return new AMQPProtocolChannelException(404, "NOT_FOUND - no queue 'x'", [50, 10]);
    }

    // ── Comandos ───────────────────────────────────────────────────

    public function test_comando_confere_a_fila_com_declare_passivo_e_publica_no_exchange_default(): void
    {
        $envelope = $this->envelope();
        $publicada = null;

        $this->canal->shouldReceive('queue_declare')->once()->with('billing.gerar-orcamento', true);
        $this->canal->shouldReceive('basic_publish')->once()
            ->with(Mockery::type(AMQPMessage::class), '', 'billing.gerar-orcamento')
            ->andReturnUsing(function (AMQPMessage $msg) use (&$publicada): void {
                $publicada = $msg;
            });
        $this->canal->shouldReceive('close')->once();

        $this->publicador()->publishCommand('billing.gerar-orcamento', $envelope);

        $this->assertInstanceOf(AMQPMessage::class, $publicada);

        $corpo = json_decode($publicada->getBody(), true);
        $this->assertSame($envelope->getMessageId(), $corpo['header']['messageId']);
        $this->assertSame('GerarOrcamento', $corpo['header']['type']);
        $this->assertSame(['osId' => 'os-1', 'itens' => [['descricao' => 'Filtro']]], $corpo['body']);

        $this->assertSame('application/json', $publicada->get('content_type'));
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $publicada->get('delivery_mode'));
        $this->assertSame($envelope->getMessageId(), $publicada->get('message_id'));
        $this->assertSame($envelope->getSagaId(), $publicada->get('correlation_id'));
        $this->assertSame('GerarOrcamento', $publicada->get('type'));
    }

    public function test_comando_leva_messageid_e_traceparent_tambem_no_header_amqp(): void
    {
        $envelope = $this->envelope();
        $publicada = null;

        $this->canal->shouldReceive('queue_declare');
        $this->canal->shouldReceive('basic_publish')->andReturnUsing(function (AMQPMessage $msg) use (&$publicada): void {
            $publicada = $msg;
        });
        $this->canal->shouldReceive('close');

        $this->publicador()->publishCommand('billing.gerar-orcamento', $envelope);

        $headers = $publicada->get('application_headers');
        $this->assertInstanceOf(AMQPTable::class, $headers);

        $nativos = $headers->getNativeData();
        $this->assertSame($envelope->getMessageId(), $nativos['messageId']);
        $this->assertSame($envelope->getSagaId(), $nativos['sagaId']);
        $this->assertSame('causa-1', $nativos['causationId']);
        $this->assertSame('GerarOrcamento', $nativos['type']);

        // O traceparent pode ser o do span PRODUCER, mas o trace e o da saga.
        $this->assertSame(self::TRACE_ID, explode('-', $nativos['traceparent'])[1]);

        // Envelope e header AMQP carregam o MESMO traceparent (contrato v1.1).
        $corpo = json_decode($publicada->getBody(), true);
        $this->assertSame($nativos['traceparent'], $corpo['header']['traceparent']);
    }

    public function test_fila_inexistente_vira_topologia_ausente_e_nada_e_publicado(): void
    {
        $this->canal->shouldReceive('queue_declare')->once()->andThrow($this->topologiaAusente());
        $this->canal->shouldNotReceive('basic_publish');

        try {
            $this->publicador()->publishCommand('billing.fila-com-nome-errado', $this->envelope());
            $this->fail('devia ter recusado publicar numa fila que nao existe');
        } catch (TopologiaAusente $e) {
            $this->assertStringContainsString('billing.fila-com-nome-errado', $e->getMessage());
            $this->assertInstanceOf(AMQPProtocolChannelException::class, $e->getPrevious());
        }
    }

    // ── Eventos ────────────────────────────────────────────────────

    public function test_evento_confere_o_exchange_com_declare_passivo_e_usa_o_tipo_como_routing_key(): void
    {
        $envelope = $this->envelope('os.cancelada');
        $publicada = null;

        $this->canal->shouldReceive('exchange_declare')->once()->with('oficina.eventos', 'topic', true);
        $this->canal->shouldReceive('basic_publish')->once()
            ->with(Mockery::type(AMQPMessage::class), 'oficina.eventos', 'os.cancelada')
            ->andReturnUsing(function (AMQPMessage $msg) use (&$publicada): void {
                $publicada = $msg;
            });
        $this->canal->shouldReceive('close')->once();

        $this->publicador()->publishEvent($envelope);

        $this->assertSame($envelope->getMessageId(), $publicada->get('message_id'));
        $this->assertSame('os.cancelada', $publicada->get('type'));
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $publicada->get('delivery_mode'));
        $this->assertSame(
            self::TRACE_ID,
            explode('-', $publicada->get('application_headers')->getNativeData()['traceparent'])[1]
        );
    }

    public function test_evento_usa_o_exchange_configurado(): void
    {
        $this->canal->shouldReceive('exchange_declare')->once()->with('outro.exchange', 'topic', true);
        $this->canal->shouldReceive('basic_publish')->once()
            ->with(Mockery::type(AMQPMessage::class), 'outro.exchange', 'os.concluida');
        $this->canal->shouldReceive('close');

        $this->publicador('outro.exchange')->publishEvent($this->envelope('os.concluida'));

        $this->assertTrue(true, 'publicou no exchange configurado, nao no default');
    }

    public function test_exchange_inexistente_vira_topologia_ausente_e_nada_e_publicado(): void
    {
        $this->canal->shouldReceive('exchange_declare')->once()->andThrow($this->topologiaAusente());
        $this->canal->shouldNotReceive('basic_publish');

        $this->expectException(TopologiaAusente::class);
        $this->expectExceptionMessage('oficina.eventos');

        $this->publicador()->publishEvent($this->envelope('os.cancelada'));
    }

    // ── Conexao ────────────────────────────────────────────────────

    public function test_reaproveita_a_conexao_viva_entre_publicacoes(): void
    {
        $this->conexao->shouldReceive('channel')->twice()->andReturn($this->canal);
        $this->canal->shouldReceive('queue_declare');
        $this->canal->shouldReceive('exchange_declare');
        $this->canal->shouldReceive('basic_publish')->twice();
        $this->canal->shouldReceive('close')->twice();

        $publicador = $this->publicador();
        $publicador->publishCommand('billing.gerar-orcamento', $this->envelope());
        $publicador->publishEvent($this->envelope('os.cancelada'));

        $prop = new ReflectionProperty(MessagePublisher::class, 'connection');
        $prop->setAccessible(true);
        $this->assertSame($this->conexao, $prop->getValue($publicador));
    }

    public function test_close_fecha_a_conexao_aberta(): void
    {
        $this->conexao->shouldReceive('close')->once();

        $this->publicador()->close();

        $this->assertTrue(true);
    }

    public function test_close_nao_mexe_em_conexao_ja_caida(): void
    {
        $this->conexao->shouldReceive('isConnected')->andReturn(false);
        $this->conexao->shouldNotReceive('close');

        $this->publicador()->close();

        $this->assertTrue(true);
    }

    public function test_close_sem_conexao_nao_faz_nada(): void
    {
        (new MessagePublisher('nao-usado', 5672, 'guest', 'guest'))->close();

        $this->assertTrue(true, 'close() sem conexao aberta nao pode estourar');
    }

    /**
     * Sem broker, a publicacao ESTOURA — e e isso que faz o OutboxPublisher
     * contar a tentativa em vez de marcar a linha como publicada.
     * Porta 1 em 127.0.0.1: recusa imediata, sem esperar timeout.
     */
    public function test_sem_broker_alcancavel_a_publicacao_estoura(): void
    {
        $publicador = new MessagePublisher('127.0.0.1', 1, 'guest', 'guest');

        $this->expectException(\Throwable::class);

        $publicador->publishCommand('billing.gerar-orcamento', $this->envelope());
    }
}
