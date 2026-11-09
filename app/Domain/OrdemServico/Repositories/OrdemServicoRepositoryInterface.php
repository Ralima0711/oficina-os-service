<?php

namespace App\Domain\OrdemServico\Repositories;

use App\Domain\OrdemServico\Entities\OrdemServico;

/**
 * CAMADA DE DOMÍNIO — Interface de Repositório
 * O domínio define O CONTRATO; a infraestrutura implementa.
 * Isso mantém o domínio independente do banco de dados.
 */
interface OrdemServicoRepositoryInterface
{
    public function findById(string $id): ?OrdemServico;
    public function findAll(): array;
    public function save(OrdemServico $ordem): OrdemServico;
    public function delete(string $id): void;
}
