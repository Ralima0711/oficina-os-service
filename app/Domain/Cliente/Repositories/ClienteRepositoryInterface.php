<?php

namespace App\Domain\Cliente\Repositories;

use App\Domain\Cliente\Entities\Cliente;

interface ClienteRepositoryInterface
{
    public function findById(string $id): ?Cliente;

    /** @return Cliente[] */
    public function findAll(): array;

    /** Documento SEM mascara, como o value object guarda em getRaw(). */
    public function findByDocumento(string $documentoRaw): ?Cliente;

    public function save(Cliente $cliente): Cliente;

    public function delete(string $id): void;
}
