<?php

namespace App\Application\Messaging;

use Ramsey\Uuid\Uuid;

/**
 * Envelope da mensagem — contrato v1.1, Parte 2.
 * header + body. O header carrega metadados de idempotência e trace.
 */
final class Envelope
{
    public function __construct(
        private string $messageId,
        private string $sagaId,
        private ?string $causationId,
        private string $type,
        private string $occurredAt,
        private string $traceparent,
        private int $version,
        private array $body,
    ) {}

    /**
     * Cria um envelope novo, gerando messageId e occurredAt.
     */
    public static function create(
        string $type,
        array $body,
        string $sagaId,
        ?string $causationId = null,
        ?string $traceparent = null,
        int $version = 1,
    ): self {
        return new self(
            messageId: Uuid::uuid4()->toString(),
            sagaId: $sagaId,
            causationId: $causationId,
            type: $type,
            occurredAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s\Z'),
            traceparent: $traceparent ?? self::generateTraceparent(),
            version: $version,
            body: $body,
        );
    }

    public static function fromArray(array $data): self
    {
        $h = $data['header'];

        return new self(
            messageId: $h['messageId'],
            sagaId: $h['sagaId'],
            causationId: $h['causationId'] ?? null,
            type: $h['type'],
            occurredAt: $h['occurredAt'],
            traceparent: $h['traceparent'] ?? '',
            version: $h['version'] ?? 1,
            body: $data['body'] ?? [],
        );
    }

    public function getMessageId(): string  { return $this->messageId; }    public function getSagaId(): string     { return $this->sagaId; }
    public function getCausationId(): ?string { return $this->causationId; }
    public function getType(): string       { return $this->type; }
    public function getOccurredAt(): string { return $this->occurredAt; }
    public function getTraceparent(): string { return $this->traceparent; }
    public function getVersion(): int       { return $this->version; }
    public function getBody(): array        { return $this->body; }

    public function toArray(): array
    {
        return [
            'header' => [
                'messageId' => $this->messageId,
                'sagaId' => $this->sagaId,
                'causationId' => $this->causationId,
                'type' => $this->type,
                'occurredAt' => $this->occurredAt,
                'traceparent' => $this->traceparent,
                'version' => $this->version,
            ],
            'body' => $this->body,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Mesmo envelope com outro traceparent. O publicador usa isto para gravar
     * o contexto do span PRODUCER: o consumidor passa a ser filho dele, e não
     * irmão do span que originou a mensagem.
     */
    public function comTraceparent(string $traceparent): self
    {
        if ($traceparent === '' || $traceparent === $this->traceparent) {
            return $this;
        }

        $novo = clone $this;
        $novo->traceparent = $traceparent;

        return $novo;
    }

    /**
     * AMQP application headers — traceparent vai nos dois lugares
     * (envelope e headers AMQP), conforme contrato v1.1, Parte 2.
     */
    public function toAmqpHeaders(): array
    {
        return [
            'messageId' => $this->messageId,
            'sagaId' => $this->sagaId,
            'causationId' => $this->causationId,
            'type' => $this->type,
            'traceparent' => $this->traceparent,
        ];
    }

    private static function generateTraceparent(): string
    {
        $traceId = bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));
        return "00-{$traceId}-{$spanId}-01";
    }
}
