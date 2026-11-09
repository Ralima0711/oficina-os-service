<?php

namespace App\Application\Saga;

use App\Application\Messaging\Envelope;
use Illuminate\Support\Facades\DB;

/**
 * Lado de escrita do outbox transacional.
 *
 * É chamado SEMPRE de dentro da transação que aplica a transição de estado.
 * Nunca toca o RabbitMQ — só grava a intenção. Quem publica é o OutboxPublisher,
 * num processo separado.
 *
 * Essa separação é o ponto todo: enquanto a publicação acontecer dentro da
 * transação, ela trava o banco esperando a rede; enquanto acontecer depois do
 * commit, ela pode nunca acontecer. O outbox é a terceira opção.
 */
class Outbox
{
    public const CANAL_COMANDO = 'comando';
    public const CANAL_EVENTO = 'evento';

    /**
     * Enfileira um comando para uma fila direta (point-to-point).
     *
     * @param string $fila nome da fila, do contrato v1.1 Parte 2
     */
    public function comando(string $fila, Envelope $envelope, string $osId): void
    {
        $this->gravar(self::CANAL_COMANDO, $fila, $envelope, $osId);
    }

    /**
     * Enfileira um evento para o exchange oficina.eventos.
     * O destino é a routing key, que por contrato é igual ao type.
     */
    public function evento(Envelope $envelope, string $osId): void
    {
        $this->gravar(self::CANAL_EVENTO, $envelope->getType(), $envelope, $osId);
    }

    private function gravar(string $canal, string $destino, Envelope $envelope, string $osId): void
    {
        if (DB::transactionLevel() === 0) {
            // Gravar fora de transação derrota o propósito: a intenção ficaria
            // sem a mudança de estado que a justifica. Falhar alto é melhor do
            // que descobrir isso em produção.
            throw new \LogicException(
                'Outbox precisa ser chamado dentro de uma transação. '
                . "Tipo: {$envelope->getType()}, OS: {$osId}"
            );
        }

        DB::table('saga_outbox')->insert([
            'message_id' => $envelope->getMessageId(),
            'saga_id' => $envelope->getSagaId(),
            'os_id' => $osId,
            'canal' => $canal,
            'destino' => $destino,
            'tipo' => $envelope->getType(),
            'payload' => $envelope->toJson(),
            'criado_em' => now(),
        ]);
    }
}
