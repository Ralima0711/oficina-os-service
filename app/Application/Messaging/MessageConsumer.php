<?php

namespace App\Application\Messaging;

use Closure;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consumidor de mensagens RabbitMQ.
 *
 * Consome de uma fila, desserializa o envelope e chama o handler.
 * ACK só depois do handler retornar sem exceção.
 */
class MessageConsumer
{
    private ?AMQPStreamConnection $connection = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private string $vhost = '/',
        private string $exchange = 'oficina.eventos',
    ) {}

    /**
     * Consome eventos de uma fila ligada à exchange por routing keys.
     *
     * @param string   $queue        Nome da fila do consumidor
     * @param string[] $routingKeys  Routing keys para bind
     * @param Closure  $handler      fn(Envelope $env): void
     */
    public function consumeEvents(string $queue, array $routingKeys, Closure $handler): void
    {
        $channel = $this->channel();

        $channel->exchange_declare($this->exchange, 'topic', false, true, false);
        $channel->queue_declare($queue, false, true, false, false);
        $channel->basic_qos(0, 1, false);

        foreach ($routingKeys as $routingKey) {
            $channel->queue_bind($queue, $this->exchange, $routingKey);
        }

        $callback = function (AMQPMessage $msg) use ($handler): void {
            try {
                $data = json_decode($msg->body, true);
                $envelope = Envelope::fromArray($data);
                $handler($envelope);
                $msg->ack();
            } catch (\Throwable $e) {
                report($e);
                $msg->nack(false, false);
            }
        };

        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
    }

    /**
     * Consome comandos de uma fila direta (point-to-point).
     *
     * @param string  $queue    Nome da fila
     * @param Closure $handler  fn(Envelope $env): void
     */
    public function consumeCommands(string $queue, Closure $handler): void
    {
        $channel = $this->channel();

        $channel->queue_declare($queue, false, true, false, false);
        $channel->basic_qos(0, 1, false);

        $callback = function (AMQPMessage $msg) use ($handler): void {
            try {
                $data = json_decode($msg->body, true);
                $envelope = Envelope::fromArray($data);
                $handler($envelope);
                $msg->ack();
            } catch (\Throwable $e) {
                report($e);
                $msg->nack(false, false);
            }
        };

        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
    }

    private function channel()
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
        if ($this->connection !== null && $this->connection->isConnected()) {
            $this->connection->close();
        }
    }
}
