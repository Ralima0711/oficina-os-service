<?php

namespace App\Infrastructure\ReadModel;

use App\Application\ReadModel\RepositorioDeLeituraInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read model no MongoDB (ADR-0009).
 *
 * Uma coleção, um documento por OS: status atual + histórico.
 * A idempotência é feita pelo array `messageIds`: o mesmo messageId não
 * entra duas vezes no histórico, mesmo com reentrega do RabbitMQ.
 */
class MongoRepositorioDeLeitura implements RepositorioDeLeituraInterface
{
    private const COLECAO = 'ordens_servico_view';

    public function registrarEvento(array $evento): void
    {
        $colecao = $this->colecao();
        $osId = $evento['osId'];

        $atual = $colecao->where('osId', $osId)->first();

        if ($atual !== null && in_array($evento['messageId'], (array) ($atual['messageIds'] ?? []), true)) {
            return; // reentrega: nada a fazer
        }

        $entrada = [
            'tipo' => $evento['tipo'],
            'status' => $evento['status'],
            'ocorridoEm' => $evento['ocorridoEm'],
            'messageId' => $evento['messageId'],
            'dados' => $evento['dados'],
        ];

        if ($atual === null) {
            $colecao->insert([
                'osId' => $osId,
                'sagaId' => $evento['sagaId'],
                'status' => $evento['status'],
                'messageIds' => [$evento['messageId']],
                'historico' => [$entrada],
                'atualizadoEm' => now()->toIso8601String(),
            ]);
            return;
        }

        $alteracoes = [
            'atualizadoEm' => now()->toIso8601String(),
        ];

        if ($evento['status'] !== null) {
            $alteracoes['status'] = $evento['status'];
        }

        $colecao->where('osId', $osId)
            ->push('historico', $entrada)
            ->push('messageIds', $evento['messageId'])
            ->update($alteracoes);
    }

    public function buscarPorOsId(string $osId): ?array
    {
        $doc = $this->colecao()->where('osId', $osId)->first();

        return $doc === null ? null : (array) $doc;
    }

    public function listar(int $limite = 50): array
    {
        return $this->colecao()
            ->orderBy('atualizadoEm', 'desc')
            ->limit($limite)
            ->get()
            ->map(fn ($doc) => (array) $doc)
            ->all();
    }

    private function colecao()
    {
        return DB::connection('mongodb')->table(self::COLECAO);
    }
}
