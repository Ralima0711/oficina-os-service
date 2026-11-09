<?php

namespace App\Application\Saga;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessagePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lado de leitura do outbox: publica as linhas pendentes no RabbitMQ.
 *
 * Roda num processo separado (php artisan saga:publicar-outbox), fora de
 * qualquer transação de domínio.
 *
 * A ordem das operações importa e é deliberada:
 *
 *   1. publica no broker
 *   2. marca como publicada
 *
 * Nessa ordem, uma queda entre os dois passos faz a mensagem sair duas vezes.
 * Na ordem inversa, faria a mensagem não sair nunca — e a saga pararia em
 * silêncio. Entrega duplicada é tratada: todo consumidor descarta messageId
 * repetido (contrato v1.1, Parte 5, regra 1). Mensagem perdida não é tratável.
 *
 * É a escolha clássica de at-least-once sobre at-most-once, e aqui ela não é
 * teórica: o messageId do envelope é o mesmo em toda republicação, porque ele
 * foi gerado uma vez só, quando a intenção entrou no outbox.
 */
class OutboxPublisher
{
    /** Acima disso, para de tentar e deixa a linha para inspeção humana. */
    public const MAX_TENTATIVAS = 10;

    public function __construct(
        private MessagePublisher $publisher,
    ) {}

    /**
     * Publica até $limite mensagens pendentes. Devolve quantas saíram.
     */
    public function publicarPendentes(int $limite = 50): int
    {
        $pendentes = DB::table('saga_outbox')
            ->whereNull('publicado_em')
            ->where('tentativas', '<', self::MAX_TENTATIVAS)
            ->orderBy('criado_em')
            ->limit($limite)
            ->get();

        $publicadas = 0;

        foreach ($pendentes as $linha) {
            if ($this->publicarUma($linha)) {
                $publicadas++;
            }
        }

        return $publicadas;
    }

    private function publicarUma(object $linha): bool
    {
        try {
            $envelope = Envelope::fromArray(
                json_decode($linha->payload, true, flags: JSON_THROW_ON_ERROR)
            );
        } catch (\JsonException $e) {
            // Payload corrompido não melhora com retry: tira da fila de uma vez.
            $this->marcarErro($linha, "payload invalido: {$e->getMessage()}", self::MAX_TENTATIVAS);
            Log::error('Outbox: payload invalido, desistindo', [
                'messageId' => $linha->message_id,
                'tipo' => $linha->tipo,
            ]);
            return false;
        }

        try {
            if ($linha->canal === Outbox::CANAL_COMANDO) {
                $this->publisher->publishCommand($linha->destino, $envelope);
            } else {
                $this->publisher->publishEvent($envelope);
            }
        } catch (\Throwable $e) {
            $this->marcarErro($linha, $e->getMessage(), $linha->tentativas + 1);

            Log::warning('Outbox: falha ao publicar, vai tentar de novo', [
                'messageId' => $linha->message_id,
                'tipo' => $linha->tipo,
                'tentativas' => $linha->tentativas + 1,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }

        // Se o processo morrer exatamente aqui, a mensagem sai de novo na
        // próxima rodada. O consumidor descarta pelo messageId.
        DB::table('saga_outbox')
            ->where('id', $linha->id)
            ->update(['publicado_em' => now(), 'ultimo_erro' => null]);

        Log::info('Outbox: publicado', [
            'messageId' => $linha->message_id,
            'tipo' => $linha->tipo,
            'canal' => $linha->canal,
            'destino' => $linha->destino,
            'sagaId' => $linha->saga_id,
        ]);

        return true;
    }

    private function marcarErro(object $linha, string $erro, int $tentativas): void
    {
        DB::table('saga_outbox')
            ->where('id', $linha->id)
            ->update([
                'tentativas' => $tentativas,
                'ultimo_erro' => mb_substr($erro, 0, 1000),
            ]);

        if ($tentativas >= self::MAX_TENTATIVAS) {
            Log::error('Outbox: desistiu apos o limite de tentativas', [
                'messageId' => $linha->message_id,
                'tipo' => $linha->tipo,
                'osId' => $linha->os_id,
                'sagaId' => $linha->saga_id,
            ]);
        }
    }

    /**
     * Linhas que desistiram. É o que um alerta deve observar: saga parada com
     * mensagem presa no outbox não aparece em lugar nenhum além daqui.
     */
    public function travadas(): \Illuminate\Support\Collection
    {
        return DB::table('saga_outbox')
            ->whereNull('publicado_em')
            ->where('tentativas', '>=', self::MAX_TENTATIVAS)
            ->orderBy('criado_em')
            ->get();
    }
}
