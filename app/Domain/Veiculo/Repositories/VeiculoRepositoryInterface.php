<?php

namespace App\Domain\Veiculo\Repositories;

use App\Domain\Veiculo\Entities\Veiculo;

interface VeiculoRepositoryInterface
{
    public function findById(string $id): ?Veiculo;

    public function pertenceAoCliente(string $veiculoId, string $clienteId): bool;

    /** @return Veiculo[] */
    public function findAll(): array;

    /** @return Veiculo[] */
    public function findDoCliente(string $clienteId): array;

    public function findByPlaca(string $placa): ?Veiculo;

    public function save(Veiculo $veiculo): Veiculo;

    public function delete(string $id): void;
}
