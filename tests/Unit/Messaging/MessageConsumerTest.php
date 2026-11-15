<?php

namespace Tests\Unit\Messaging;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessageConsumer;
use App\Application\Messaging\TopologiaAusente;
use App\Domain\OrdemServico\Exceptions\LostUpdateException;
use App\Infrastructure\Observability\OpenTelemetryProvider;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * O laco de consumo e a politica de ACK/NACK do MessageConsumer, sem broker.
 *
 * O MessageConsumerCompatibilidadeTest trava a API do php-amqplib instalado
 * (delivery tag, headers). Este trava o resto do contrato do consumidor, o que
 * o docs/saga-maquina-estados.md pede:
 *
 *   ilegivel                         -> nack sem requeue (DLQ)
 *   transitorio (banco, version)     -> nack COM requeue, ate o limite
 *   transitorio que passou do limite -> nack sem requeue (DLQ)
 *   qualquer outra excecao           -> nack sem requeue (DLQ)
 *   sucesso                          -> ack
 *
 * e o laco em volta: declare passivo, prefetch 1, ack manual, saida limpa no
 * SIGTERM. A conexao e dublada e injetada na propriedade privada; o resto e o
 * codigo de producao.
 */
class MessageConsumerTest extends TestCase
{
    private const TAG = 7;

    private MockInterface $conexao;
    private MockInterface $canal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->canal = Mockery::mock(AMQPChannel::class);
        $this->canal->callbacks = [];

        $this->conexao = Mockery::mock(AMQPStreamConnection::class);
        $this->conexao->shouldReceive('isConnected')->andReturn(true)->byDefault();
        $this->conexao->shouldReceive('channel')->andReturn($this->canal)->byDefault();
        $this->conexao->shouldReceive('close')->byDefault();
    }

    protected function tearDown(): void
    {
        // O consumidor registra handler de SIGTERM/SIGINT no processo. Fora
        // daqui, o processo do PHPUnit tem que voltar a morrer no sinal.
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }

        OpenTelemetryProvider::reiniciar();
        parent::tearDown();
    }

    private function consumidor(): MessageConsumer
    {
        $consumidor = new MessageConsumer('nao-usado', 5672, 'guest', 'guest');

        $prop = new ReflectionProperty(MessageConsumer::class, 'connection');
        $prop->setAccessible(true);
        $prop->setValue($consumidor, $this->conexao);

        return $consumidor;
    }

    private function envelope(): Envelope
    {
        return Envelope::create('orcamento.gerado', ['osId' => 'os-1'], 'saga-1');
    }

    private function mensagem(Envelope $env): AMQPMessage
    {
        $msg = new AMQPMessage($env->toJson(), ['content_type' => 'application/json']);
        $msg->delivery_info['delivery_tag'] = self::TAG;

        return $msg;
    }

    private function tratar(MessageConsumer $consumidor, AMQPMessage $msg, Closure $handler): void
    {
        $metodo = new ReflectionMethod(MessageConsumer::class, 'tratar');
        $metodo->setAccessible(true);
        $metodo->invoke($consumidor, $msg, $handler, $this->canal);
    }

    /** @return array<string, int> */
    private function tentativas(MessageConsumer $consumidor): array
    {
        $prop = new ReflectionProperty(MessageConsumer::class, 'tentativas');
        $prop->setAccessible(true);

        return $prop->getValue($consumidor);
    }

    private function definirTentativas(MessageConsumer $consumidor, array $tentativas): void
    {
        $prop = new ReflectionProperty(MessageConsumer::class, 'tentativas');
        $prop->setAccessible(true);
        $prop->setValue($consumidor, $tentativas);
    }

    /**
     * Prepara o canal para entregar $mensagens, uma por wait(), e encerrar o
     * consumo depois da ultima — como o broker faz quando cancela o consumer.
     */
    private function entregar(string $fila, array $mensagens): void
    {
        $callback = null;

        $this->canal->shouldReceive('queue_declare')->once()->with($fila, true);
        $this->canal->shouldReceive('basic_qos')->once()->with(0, 1, false);
        $this->canal->shouldReceive('basic_consume')->once()
            ->with($fila, '', false, false, false, false, Mockery::type(Closure::class))
            ->andReturnUsing(function (...$args) use (&$callback): string {
                $callback = $args[6];
                $this->canal->callbacks = ['ctag' => $callback];

                return 'ctag';
            });

        $this->canal->shouldReceive('wait')->andReturnUsing(function () use (&$callback, &$mensagens): void {
            $callback(array_shift($mensagens));

            if ($mensagens === []) {
                $this->canal->callbacks = [];
            }
        });
    }

    private function filaInexistente(): AMQPProtocolChannelException
    {
        // Ver MessagePublisherTest::topologiaAusente().
        AbstractChannel::$PROTOCOL_CONSTANTS_CLASS ??= \PhpAmqpLib\Wire\Constants091::class;

        return new AMQPProtocolChannelException(404, "NOT_FOUND - no queue 'x'", [50, 10]);
    }

    // ── Laco de consumo ────────────────────────────────────────────

    public function test_consome_eventos_entregando_envelopes_ao_handler_e_confirmando_cada_um(): void
    {
        $a = $this->envelope();
        $b = $this->envelope();
        $this->entregar('os.saga.eventos', [$this->mensagem($a), $this->mensagem($b)]);

        $this->canal->shouldReceive('basic_ack')->twice()->with(self::TAG);
        $this->canal->shouldNotReceive('basic_nack');
        $this->conexao->shouldReceive('close')->once();

        $recebidos = [];

        $this->consumidor()->consumeEvents(
            'os.saga.eventos',
            ['orcamento.gerado'],
            function (Envelope $e) use (&$recebidos): void {
                $recebidos[] = $e->getMessageId();
            },
        );

        $this->assertSame([$a->getMessageId(), $b->getMessageId()], $recebidos);
    }

    public function test_consome_comandos_da_fila_direta(): void
    {
        $env = $this->envelope();
        $this->entregar('os.comandos', [$this->mensagem($env)]);
        $this->canal->shouldReceive('basic_ack')->once()->with(self::TAG);

        $recebido = null;

        $this->consumidor()->consumeCommands('os.comandos', function (Envelope $e) use (&$recebido): void {
            $recebido = $e;
        });

        $this->assertSame($env->getMessageId(), $recebido?->getMessageId());
        $this->assertSame(['osId' => 'os-1'], $recebido->getBody());
    }

    /**
     * Fila inexistente ESTOURA: devolver em silencio fazia o worker sair com
     * codigo 0 sem ter consumido nada, e o Deployment reiniciava em laco.
     */
    public function test_fila_inexistente_estoura_topologia_ausente_sem_comecar_a_consumir(): void
    {
        Log::spy();

        $this->canal->shouldReceive('queue_declare')->once()->andThrow($this->filaInexistente());
        $this->canal->shouldNotReceive('basic_consume');

        try {
            $this->consumidor()->consumeCommands('fila.que.nao.existe', fn () => null);
            $this->fail('devia ter estourado TopologiaAusente');
        } catch (TopologiaAusente $e) {
            $this->assertStringContainsString('fila.que.nao.existe', $e->getMessage());
            $this->assertInstanceOf(AMQPProtocolChannelException::class, $e->getPrevious());
        }

        Log::shouldHaveReceived('error')->once();
    }

    public function test_erro_do_broker_no_meio_do_consumo_propaga_e_fecha_a_conexao(): void
    {
        $this->canal->shouldReceive('queue_declare');
        $this->canal->shouldReceive('basic_qos');
        $this->canal->shouldReceive('basic_consume')->andReturnUsing(function () {
            $this->canal->callbacks = ['ctag' => fn () => null];

            return 'ctag';
        });
        $this->canal->shouldReceive('wait')->once()->andThrow(new AMQPRuntimeException('conexao caiu'));
        $this->conexao->shouldReceive('close')->once();

        $this->expectException(AMQPRuntimeException::class);

        $this->consumidor()->consumeCommands('os.comandos', fn () => null);
    }

    /**
     * SIGTERM do Kubernetes: o consumidor fecha o canal, o wait() em curso
     * estoura por isso, e a excecao e engolida porque foi parada pedida — o
     * processo sai limpo em vez de sair com erro.
     */
    public function test_sigterm_encerra_o_consumo_sem_erro(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('precisa de pcntl e posix para mandar o sinal ao proprio processo');
        }

        Log::spy();

        $this->canal->shouldReceive('queue_declare');
        $this->canal->shouldReceive('basic_qos');
        $this->canal->shouldReceive('basic_consume')->andReturnUsing(function () {
            $this->canal->callbacks = ['ctag' => fn () => null];

            return 'ctag';
        });
        $this->canal->shouldReceive('close')->once();
        $this->canal->shouldReceive('wait')->once()->andReturnUsing(function (): void {
            posix_kill(getmypid(), SIGTERM);
            pcntl_signal_dispatch();

            throw new AMQPRuntimeException('canal fechado pelo sinal');
        });
        $this->conexao->shouldReceive('close')->once();

        $this->consumidor()->consumeCommands('os.comandos', fn () => null);

        Log::shouldHaveReceived('info')->with('Consumidor: sinal recebido, encerrando')->once();
    }

    // ── Politica de ACK/NACK ───────────────────────────────────────

    public function test_falha_de_banco_e_transitoria_e_reentrega_com_requeue(): void
    {
        Log::spy();

        $env = $this->envelope();
        $this->canal->shouldReceive('basic_nack')->once()->with(self::TAG, false, true);
        $this->canal->shouldNotReceive('basic_ack');

        $consumidor = $this->consumidor();

        $this->tratar($consumidor, $this->mensagem($env), function (): void {
            throw new QueryException('pgsql', 'update ordens_servico ...', [], new \PDOException('server closed the connection'));
        });

        $this->assertSame([$env->getMessageId() => 1], $this->tentativas($consumidor));
        Log::shouldHaveReceived('warning')->with('Consumidor: erro transitório, reentregando', Mockery::on(
            fn (array $ctx) => $ctx['messageId'] === $env->getMessageId() && $ctx['tentativa'] === 1 && $ctx['de'] === 5
        ))->once();
    }

    public function test_transitorio_que_persiste_vai_para_a_dlq_no_limite_e_zera_o_contador(): void
    {
        Log::spy();

        $env = $this->envelope();
        $consumidor = $this->consumidor();
        // Quatro reentregas ja feitas: a quinta e a ultima.
        $this->definirTentativas($consumidor, [$env->getMessageId() => 4]);

        $this->canal->shouldReceive('basic_nack')->once()->with(self::TAG, false, false);

        $this->tratar($consumidor, $this->mensagem($env), function (): void {
            throw new AMQPRuntimeException('broker sumiu no meio do handler');
        });

        $this->assertSame([], $this->tentativas($consumidor));
        Log::shouldHaveReceived('error')->with('Consumidor: erro transitório persistiu, para a DLQ', Mockery::on(
            fn (array $ctx) => $ctx['tentativas'] === 5
        ))->once();
    }

    public function test_colisao_de_version_e_transitoria(): void
    {
        $env = $this->envelope();
        $consumidor = $this->consumidor();
        $this->definirTentativas($consumidor, [$env->getMessageId() => 4]);

        // No limite, para nao esperar a pausa entre reentregas: o que importa
        // aqui e que LostUpdate entrou no caminho transitorio, e nao no da DLQ
        // direta — o contador prova isso.
        $this->canal->shouldReceive('basic_nack')->once()->with(self::TAG, false, false);

        $this->tratar($consumidor, $this->mensagem($env), function (): void {
            throw new LostUpdateException('os-1', 3);
        });

        $this->assertSame([], $this->tentativas($consumidor), 'o contador de transitorios foi usado e zerado');
    }

    public function test_pdo_exception_crua_tambem_e_transitoria(): void
    {
        $env = $this->envelope();
        $consumidor = $this->consumidor();
        $this->definirTentativas($consumidor, [$env->getMessageId() => 4]);

        $this->canal->shouldReceive('basic_nack')->once()->with(self::TAG, false, false);

        $this->tratar($consumidor, $this->mensagem($env), function (): void {
            throw new \PDOException('SQLSTATE[08006] connection failure');
        });

        $this->assertSame([], $this->tentativas($consumidor));
    }

    public function test_excecao_de_negocio_vai_direto_para_a_dlq_sem_reentrega(): void
    {
        Log::spy();

        $env = $this->envelope();
        $consumidor = $this->consumidor();

        $this->canal->shouldReceive('basic_nack')->once()->with(self::TAG, false, false);
        $this->canal->shouldNotReceive('basic_ack');

        $this->tratar($consumidor, $this->mensagem($env), function (): void {
            throw new \DomainException('transicao invalida');
        });

        $this->assertSame([], $this->tentativas($consumidor), 'erro de negocio nao conta como transitorio');
        Log::shouldHaveReceived('error')->with('Consumidor: falha não tratada, para a DLQ', Mockery::on(
            fn (array $ctx) => $ctx['messageId'] === $env->getMessageId() && $ctx['erro'] === 'transicao invalida'
        ))->once();
    }

    public function test_sucesso_depois_de_reentrega_zera_o_contador_da_mensagem(): void
    {
        $env = $this->envelope();
        $consumidor = $this->consumidor();
        $this->definirTentativas($consumidor, [$env->getMessageId() => 2, 'outra' => 1]);

        $this->canal->shouldReceive('basic_ack')->once()->with(self::TAG);

        $this->tratar($consumidor, $this->mensagem($env), fn () => null);

        $this->assertSame(['outra' => 1], $this->tentativas($consumidor));
    }

    // ── Conexao ────────────────────────────────────────────────────

    public function test_close_engole_erro_de_conexao_ja_fechada_pelo_sinal(): void
    {
        $this->conexao->shouldReceive('close')->once()->andThrow(new AMQPRuntimeException('ja fechada'));

        $this->consumidor()->close();

        $this->assertTrue(true, 'close() nao pode estourar no encerramento');
    }

    public function test_sem_broker_alcancavel_o_consumo_estoura(): void
    {
        $consumidor = new MessageConsumer('127.0.0.1', 1, 'guest', 'guest');

        $this->expectException(\Throwable::class);

        $consumidor->consumeCommands('os.comandos', fn () => null);
    }
}
