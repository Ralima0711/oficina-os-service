<?php

namespace App\Application\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Publicador de mensagens RabbitMQ.
 *
 * Comandos → fila direta (point-to-point).
 * Eventos  → exchange oficina.eventos (pub-sub, routing key = tipo).
 */
class MessagePublisher
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
     * Publica um comando numa fila direta (point-to-point).
     */
    public function publishCommand(string $queue, Envelope $envelope): void
    {
        $channel = $this->channel();

        $channel->queue_declare($queue, false, true, false, false);

        $msg = new AMQPMessage(
            $envelope->toJson(),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $envelope->getMessageId(),
                'correlation_id' => $envelope->getSagaId(),
                'type' => $envelope->getType(),
                'timestamp' => time(),
                'application_headers' => new AMQPTable($this->amqpTable($envelope)),
            ]
        );

        $channel->basic_publish($msg, '', $queue);
        $channel->close();
    }

    /**
     * Publica um evento na exchange topic (pub-sub).
     */
    public function publishEvent(Envelope $envelope): void
    {
        $channel = $this->channel();

        $channel->exchange_declare(
            $this->exchange,
            'topic',
            false,
            true,
            false
        );

        $msg = new AMQPMessage(
            $envelope->toJson(),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $envelope->getMessageId(),
                'correlation_id' => $envelope->getSagaId(),
                'type' => $envelope->getType(),
                'timestamp' => time(),
                'application_headers' => new AMQPTable($this->amqpTable($envelope)),
            ]
        );

        $channel->basic_publish($msg, $this->exchange, $envelope->getType());
        $channel->close();
    }

    private function amqpTable(Envelope $envelope): array
    {
        return $envelope->toAmqpHeaders();
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
