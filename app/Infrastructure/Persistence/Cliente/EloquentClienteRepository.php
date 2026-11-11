<?php

namespace App\Infrastructure\Persistence\Cliente;

use App\Domain\Cliente\Entities\Cliente;
use App\Domain\Cliente\Repositories\ClienteRepositoryInterface;
use App\Domain\Cliente\ValueObjects\Cnpj;
use App\Domain\Cliente\ValueObjects\Cpf;
use App\Domain\Cliente\ValueObjects\DocumentoFiscal;
use App\Infrastructure\Persistence\Models\ClienteModel;

class EloquentClienteRepository implements ClienteRepositoryInterface
{
    public const PESSOA_FISICA = 'pessoa_fisica';
    public const PESSOA_JURIDICA = 'pessoa_juridica';

    public function findById(string $id): ?Cliente
    {
        $m = ClienteModel::find($id);

        return $m === null ? null : $this->toEntity($m);
    }

    /** @return Cliente[] */
    public function findAll(): array
    {
        return ClienteModel::orderBy('nome')->get()
            ->map(fn (ClienteModel $m) => $this->toEntity($m))
            ->all();
    }

    public function findByDocumento(string $documentoRaw): ?Cliente
    {
        $limpo = preg_replace('/\D/', '', $documentoRaw);
        $m = ClienteModel::where('documento', $limpo)->first();

        return $m === null ? null : $this->toEntity($m);
    }

    public function save(Cliente $cliente): Cliente
    {
        // foiPersistido(), nao getId() === null: criar() ja gera o uuid.
        $dados = [
            'nome' => $cliente->getNome(),
            'tipo' => $cliente->getTipo(),
            'documento' => $cliente->getDocumento()->getRaw(),
            'telefone' => $cliente->getTelefone(),
            'email' => $cliente->getEmail(),
        ];

        if (! $cliente->foiPersistido()) {
            ClienteModel::create(['id' => $cliente->getId()] + $dados);
            $cliente->marcarComoPersistido();

            return $cliente;
        }

        ClienteModel::where('id', $cliente->getId())->update($dados);

        return $cliente;
    }

    public function delete(string $id): void
    {
        ClienteModel::where('id', $id)->delete();
    }

    private function toEntity(ClienteModel $m): Cliente
    {
        $cliente = new Cliente(
            id: $m->id,
            nome: $m->nome,
            tipo: $m->tipo,
            documento: $this->documento($m->tipo, $m->documento),
            telefone: $m->telefone,
            email: $m->email,
            criadoEm: new \DateTimeImmutable((string) $m->criado_em),
        );

        $cliente->marcarComoPersistido();

        return $cliente;
    }

    /** O tipo decide o value object, e o value object revalida o que veio do banco. */
    private function documento(string $tipo, string $raw): DocumentoFiscal
    {
        return $tipo === self::PESSOA_JURIDICA ? new Cnpj($raw) : new Cpf($raw);
    }
}
