<?php

namespace App\Application\ReadModel;

/**
 * Porta de leitura do CQRS (ADR-0009).
 *
 * O read model vive no MongoDB, mas o projetor não conhece o driver:
 * depende desta interface. Isso mantém o projetor testável sem banco.
 */
interface RepositorioDeLeituraInterface
{
    /**
     * Grava o estado atual da OS e acrescenta a entrada no histórico.
     * Idempotente por messageId: repetir a mesma mensagem não duplica histórico.
     *
     * @param array{
     *     osId: string,
     *     status: string,
     *     messageId: string,
     *     tipo: string,
     *     ocorridoEm: string,
     *     sagaId: ?string,
     *     dados: array
     * } $evento
     */
    public function registrarEvento(array $evento): void;

    public function buscarPorOsId(string $osId): ?array;

    /** @return array<int, array> */
    public function listar(int $limite = 50): array;
}
