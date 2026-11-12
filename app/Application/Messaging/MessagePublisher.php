<?php

namespace App\Application\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Publicador de mensagens RabbitMQ.
 *
 * Comandos → fila direta (point-to-point).
 * Eventos  → exchange oficina.eventos (pub-sub, routing key = tipo).
 *
 * Este publicador NAO cria topologia. A topologia vive num lugar so, o
 * rabbitmq/definitions.json do oficina-infra-fase4, que o compose monta e o
 * cluster recebe por ConfigMap. Aqui so se CONFERE que ela existe, com declare
 * passivo — igual ao que o MessageConsumer ja fazia.
 *
 * Antes daqui o publishCommand fazia declare ATIVO:
 *
 *     $channel->queue_declare($queue, false, true, false, false);
 *
 * As filas de comando existem com x-dead-letter-exchange e
 * x-dead-letter-routing-key. Redeclarar sem esses argumentos e
 * PRECONDITION_FAILED 406 em AMQP, entao TODA publicacao de comando falhava e
 * a saga nunca saia do primeiro passo. O outbox segurava a mensagem, tentava
 * ate o teto e alertava — fazendo o papel dele, mas o comando nao chegava.
 *
 * O declare passivo tambem protege contra o erro mais silencioso desta
 * arquitetura: publicar numa fila com nome errado. No exchange default, uma
 * mensagem para fila inexistente e descartada sem erro nenhum.
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

        $this->exigirFila($channel, $queue);

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

        $this->exigirExchange($channel);

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

    /**
     * Declaracao passiva: confere que a fila existe, sem tentar cria-la nem
     * opinar sobre os argumentos dela.
     */
    private function exigirFila(AMQPChannel $channel, string $queue): void
    {
        try {
            $channel->queue_declare($queue, true);
        } catch (AMQPProtocolChannelException $e) {
            throw new TopologiaAusente(
                "Fila {$queue} nao existe neste broker. A topologia vem do "
                . 'rabbitmq/definitions.json no oficina-infra-fase4.',
                previous: $e
            );
        }
    }

    private function exigirExchange(AMQPChannel $channel): void
    {
        try {
            $channel->exchange_declare($this->exchange, 'topic', true);
        } catch (AMQPProtocolChannelException $e) {
            throw new TopologiaAusente(
                "Exchange {$this->exchange} nao existe neste broker. A topologia "
                . 'vem do rabbitmq/definitions.json no oficina-infra-fase4.',
                previous: $e
            );
        }
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
