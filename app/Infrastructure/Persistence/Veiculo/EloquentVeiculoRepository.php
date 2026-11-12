<?php

namespace App\Infrastructure\Persistence\Veiculo;

use App\Domain\Veiculo\Entities\Veiculo;
use App\Domain\Veiculo\Repositories\VeiculoRepositoryInterface;
use App\Domain\Veiculo\ValueObjects\Placa;
use App\Infrastructure\Persistence\Models\VeiculoModel;

class EloquentVeiculoRepository implements VeiculoRepositoryInterface
{
    public function findById(string $id): ?Veiculo
    {
        $m = VeiculoModel::find($id);

        return $m === null ? null : $this->toEntity($m);
    }

    public function pertenceAoCliente(string $veiculoId, string $clienteId): bool
    {
        return VeiculoModel::where('id', $veiculoId)
            ->where('cliente_id', $clienteId)
            ->exists();
    }

    /** @return Veiculo[] */
    public function findAll(): array
    {
        return VeiculoModel::orderBy('placa')->get()
            ->map(fn (VeiculoModel $m) => $this->toEntity($m))
            ->all();
    }

    /** @return Veiculo[] */
    public function findDoCliente(string $clienteId): array
    {
        return VeiculoModel::where('cliente_id', $clienteId)->orderBy('placa')->get()
            ->map(fn (VeiculoModel $m) => $this->toEntity($m))
            ->all();
    }

    public function findByPlaca(string $placa): ?Veiculo
    {
        $normalizada = (string) new Placa($placa);
        $m = VeiculoModel::where('placa', $normalizada)->first();

        return $m === null ? null : $this->toEntity($m);
    }

    public function save(Veiculo $veiculo): Veiculo
    {
        $dados = [
            'cliente_id' => $veiculo->getClienteId(),
            'placa' => (string) $veiculo->getPlaca(),
            'marca' => $veiculo->getMarca(),
            'modelo' => $veiculo->getModelo(),
            'ano' => $veiculo->getAno(),
            'cor' => $veiculo->getCor(),
        ];

        if (! $veiculo->foiPersistido()) {
            VeiculoModel::create(['id' => $veiculo->getId()] + $dados);
            $veiculo->marcarComoPersistido();

            return $veiculo;
        }

        VeiculoModel::where('id', $veiculo->getId())->update($dados);

        return $veiculo;
    }

    public function delete(string $id): void
    {
        VeiculoModel::where('id', $id)->delete();
    }

    private function toEntity(VeiculoModel $m): Veiculo
    {
        $veiculo = new Veiculo(
            id: $m->id,
            clienteId: $m->cliente_id,
            placa: new Placa($m->placa),
            marca: $m->marca,
            modelo: $m->modelo,
            ano: (int) $m->ano,
            cor: $m->cor,
            criadoEm: new \DateTimeImmutable((string) $m->criado_em),
        );

        $veiculo->marcarComoPersistido();

        return $veiculo;
    }
}
