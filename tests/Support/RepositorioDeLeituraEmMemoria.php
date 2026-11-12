<?php

namespace Tests\Support;

use App\Application\ReadModel\RepositorioDeLeituraInterface;

/**
 * Read model em memória, para testar o projetor sem MongoDB.
 */
class RepositorioDeLeituraEmMemoria implements RepositorioDeLeituraInterface
{
    /** @var array<string, array> */
    public array $documentos = [];

    public function garantirIndices(): void
    {
        // Em memoria nao ha indice: o array ja e unico por osId.
    }

    public function registrarEvento(array $evento): void
    {
        $osId = $evento['osId'];

        if (isset($this->documentos[$osId])
            && in_array($evento['messageId'], $this->documentos[$osId]['messageIds'], true)) {
            return;
        }

        $entrada = [
            'tipo' => $evento['tipo'],
            'status' => $evento['status'],
            'ocorridoEm' => $evento['ocorridoEm'],
            'messageId' => $evento['messageId'],
            'dados' => $evento['dados'],
        ];

        if (!isset($this->documentos[$osId])) {
            $this->documentos[$osId] = [
                'osId' => $osId,
                'sagaId' => $evento['sagaId'],
                'status' => $evento['status'],
                'messageIds' => [$evento['messageId']],
                'historico' => [$entrada],
            ];
            return;
        }

        if ($evento['status'] !== null) {
            $this->documentos[$osId]['status'] = $evento['status'];
        }

        $this->documentos[$osId]['messageIds'][] = $evento['messageId'];
        $this->documentos[$osId]['historico'][] = $entrada;
    }

    public function buscarPorOsId(string $osId): ?array
    {
        return $this->documentos[$osId] ?? null;
    }

    public function listar(int $limite = 50): array
    {
        return array_slice(array_values($this->documentos), 0, $limite);
    }
}
