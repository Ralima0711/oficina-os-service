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

    /**
     * Garante os indices de que a idempotencia depende.
     *
     * Esta na porta, e nao so no adaptador, porque e pre-condicao do contrato
     * acima: sem indice unico em osId, dois upserts concorrentes para uma OS
     * que ainda nao existe inserem dois documentos e "um documento por OS"
     * deixa de valer. Quem implementa em memoria nao precisa fazer nada.
     */
    public function garantirIndices(): void;

    public function buscarPorOsId(string $osId): ?array;

    /** @return array<int, array> */
    public function listar(int $limite = 50): array;
}
