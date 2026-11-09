<?php

namespace App\Application\Messaging;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use JsonException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Consumidor de mensagens RabbitMQ.
 *
 * Duas mudanças em relação à primeira versão, as duas vindas do
 * docs/saga-maquina-estados.md:
 *
 * 1. A fila é declarada PASSIVA. A topologia é do rabbitmq/definitions.json no
 *    oficina-infra-fase4, e lá as filas têm x-dead-letter-exchange nos
 *    argumentos. Declarar de novo com argumentos diferentes dá
 *    PRECONDITION_FAILED 406 e derruba o worker no start. Aqui só se confere
 *    que a fila existe. O bind também é do infra: ligar daqui criaria binding
 *    que não está no definitions.json e o ambiente passaria a divergir do que
 *    está versionado.
 *
 * 2. O nack deixou de ser um só. A regra 3 e a seção 5 do doc dizem que evento
 *    fora de ordem é descartado com log e ACK, e que a DLQ é só para mensagem
 *    ilegível. Então:
 *
 *      ilegível (JSON quebrado, envelope sem header)  → nack sem requeue → DLQ
 *      transitório (banco fora, colisão de version)   → nack com requeue, até o limite
 *      passou do limite de tentativas                 → nack sem requeue → DLQ, com log de alerta
 *      qualquer outra exceção                         → nack sem requeue → DLQ
 *      sucesso, inclusive descarte por ordem          → ACK
 *
 *    O descarte por ordem não aparece aqui porque o Orchestrator trata OrdemFora
 *    internamente e retorna sem exceção: para este consumidor é sucesso, e vira
 *    ACK. É o que o doc pede.
 */
class MessageConsumer
{
    /** Tentativas de reentrega por mensagem antes de mandar para a DLQ. */
    private const MAX_TENTATIVAS = 5;

    /** Segundos entre reentregas de erro transitório. */
    private const ESPERA_SEGUNDOS = 2;

    private ?AMQPStreamConnection $connection = null;

    /** @var array<string, int> messageId → tentativas já feitas neste processo */
    private array $tentativas = [];

    private bool $encerrar = false;

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private string $vhost = '/',
        private string $exchange = 'oficina.eventos',
    ) {}

    /**
     * Consome de uma fila que já existe no broker.
     *
     * @param string $queue fila declarada no definitions.json do infra
     * @param string[] $routingKeys só para log: o bind é do infra
     * @param Closure $handler fn(Envelope $env): void
     */
    public function consumeEvents(string $queue, array $routingKeys, Closure $handler): void
    {
        Log::info('Consumidor: iniciando', [
            'fila' => $queue,
            'exchange' => $this->exchange,
            'routingKeysEsperadas' => $routingKeys,
        ]);

        $this->consume($queue, $handler);
    }

    /**
     * Consome comandos de uma fila direta (point-to-point).
     *
     * @param Closure $handler fn(Envelope $env): void
     */
    public function consumeCommands(string $queue, Closure $handler): void
    {
        $this->consume($queue, $handler);
    }

    private function consume(string $queue, Closure $handler): void
    {
        $channel = $this->channel();

        if (!$this->confereFila($channel, $queue)) {
            return;
        }

        $this->escutarParada($channel);
        $channel->basic_qos(0, 1, false);

        $channel->basic_consume(
            $queue,
            '',
            false,
            false,      // no_ack = false: o ACK é nosso
            false,
            false,
            fn (AMQPMessage $msg) => $this->tratar($msg, $handler, $channel),
        );

        try {
            while ($channel->is_consuming() && !$this->encerrar) {
                $channel->wait();
            }
        } catch (Throwable $e) {
            if (!$this->encerrar) {
                throw $e;
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Declaração passiva: confere que a fila existe sem tentar criá-la.
     *
     * Em AMQP um declare passivo que falha derruba o canal, então não dá para
     * seguir usando a mesma conexão depois — por isso o retorno booleano em vez
     * de uma exceção tratada no laço.
     */
    private function confereFila(AMQPChannel $channel, string $queue): bool
    {
        try {
            $channel->queue_declare($queue, true);
            return true;
        } catch (AMQPProtocolChannelException $e) {
            Log::error(
                "Fila {$queue} não existe neste broker. A topologia vem do "
                . 'rabbitmq/definitions.json no oficina-infra-fase4.',
                ['erro' => $e->getMessage()]
            );
            return false;
        }
    }

    private function tratar(AMQPMessage $msg, Closure $handler, AMQPChannel $channel): void
    {
        $tag = $msg->getDeliveryTag();
        $messageId = null;

        try {
            $dados = json_decode($msg->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($dados) || !isset($dados['header']['messageId'])) {
                throw new MensagemIlegivel('envelope sem header.messageId.');
            }

            $messageId = (string) $dados['header']['messageId'];
            $envelope = Envelope::fromArray($this->priorizarTraceparent($dados, $msg));

            $handler($envelope);

            unset($this->tentativas[$messageId]);
            $channel->basic_ack($tag);
        } catch (JsonException|MensagemIlegivel $e) {
            // Ilegível: reentregar não ajuda, o conteúdo não vai melhorar.
            Log::warning('Consumidor: mensagem ilegível, para a DLQ', ['erro' => $e->getMessage()]);
            $channel->basic_nack($tag, false, false);
        } catch (QueryException|AMQPRuntimeException $e) {
            $this->tratarTransitorio($e, $msg, $channel, $messageId);
        } catch (Throwable $e) {
            if ($this->ehTransitorio($e)) {
                $this->tratarTransitorio($e, $msg, $channel, $messageId);
                return;
            }

            report($e);
            Log::error('Consumidor: falha não tratada, para a DLQ', [
                'messageId' => $messageId,
                'erro' => $e->getMessage(),
            ]);
            $channel->basic_nack($tag, false, false);
        }
    }

    /**
     * Erro transitório: reentrega até o limite, depois DLQ com alerta.
     *
     * Fecha a pendência 2 da seção 6 do doc (limite e intervalo de retries na
     * colisão de version). O contador vive no processo, então reinício do pod
     * zera a conta — é aceitável: o que ele evita é o laço apertado, e um pod
     * que reinicia já introduziu a espera sozinho.
     */
    private function tratarTransitorio(
        Throwable $e,
        AMQPMessage $msg,
        AMQPChannel $channel,
        ?string $messageId,
    ): void {
        $chave = $messageId ?? 'sem-id';
        $feitas = ($this->tentativas[$chave] ?? 0) + 1;
        $this->tentativas[$chave] = $feitas;

        if ($feitas >= self::MAX_TENTATIVAS) {
            unset($this->tentativas[$chave]);
            report($e);
            Log::error('Consumidor: erro transitório persistiu, para a DLQ', [
                'messageId' => $messageId,
                'tentativas' => $feitas,
                'erro' => $e->getMessage(),
            ]);
            $channel->basic_nack($msg->getDeliveryTag(), false, false);
            return;
        }

        Log::warning('Consumidor: erro transitório, reentregando', [
            'messageId' => $messageId,
            'tentativa' => $feitas,
            'de' => self::MAX_TENTATIVAS,
            'erro' => $e->getMessage(),
        ]);

        sleep(self::ESPERA_SEGUNDOS);
        $channel->basic_nack($msg->getDeliveryTag(), false, true);
    }

    /** Colisão de version é transitória: outro consumidor venceu, vale tentar de novo. */
    private function ehTransitorio(Throwable $e): bool
    {
        return $e instanceof \App\Domain\OrdemServico\Exceptions\LostUpdateException
            || $e instanceof \PDOException;
    }

    /**
     * Contrato v1.1, Parte 2: o traceparent vai no envelope e no header AMQP.
     * Divergiu, o header AMQP vence.
     */
    private function priorizarTraceparent(array $dados, AMQPMessage $msg): array
    {
        $headers = $msg->get_properties()['application_headers'] ?? null;

        if (!$headers instanceof AMQPTable) {
            return $dados;
        }

        $doAmqp = $headers->getNativeData()['traceparent'] ?? null;

        if (is_string($doAmqp) && $doAmqp !== '') {
            $dados['header']['traceparent'] = $doAmqp;
        }

        return $dados;
    }

    /** SIGTERM do Kubernetes encerra depois da mensagem atual, não no meio dela. */
    private function escutarParada(AMQPChannel $channel): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $sinal) {
            pcntl_signal($sinal, function () use ($channel) {
                $this->encerrar = true;
                Log::info('Consumidor: sinal recebido, encerrando');

                try {
                    $channel->close();
                } catch (Throwable) {
                    // O wait() em curso vê o canal fechado e o laço termina.
                }
            });
        }
    }

    private function channel(): AMQPChannel
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            $this->connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->password,
                $this->vhost,
            );
        }

        return $this->connection->channel();
    }

    public function close(): void
    {
        try {
            if ($this->connection !== null && $this->connection->isConnected()) {
                $this->connection->close();
            }
        } catch (Throwable) {
            // Já pode ter sido fechada pelo sinal de parada.
        }
    }
}

/** Mensagem que não dá para interpretar: vai para a DLQ sem reentrega. */
class MensagemIlegivel extends \RuntimeException {}
