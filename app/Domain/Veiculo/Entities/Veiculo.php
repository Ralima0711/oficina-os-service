<?php

namespace App\Domain\Veiculo\Entities;

use App\Domain\Veiculo\ValueObjects\Placa;
use Ramsey\Uuid\Uuid;

/**
 * CAMADA DE DOMINIO — Entidade Veiculo
 *
 * Mesma historia do Cliente: o ADR-0007 coloca veiculo no OS Service e o
 * recorte parou no scaffolding. Identidade em uuid pelo mesmo motivo —
 * ordens_servico.veiculo_id e coluna `uuid`.
 */
class Veiculo implements \JsonSerializable
{
    private bool $persistido = false;

    public function __construct(
        private ?string $id,
        private string $clienteId,
        private Placa $placa,
        private string $marca,
        private string $modelo,
        private int $ano,
        private ?string $cor,
        private \DateTimeImmutable $criadoEm,
    ) {}

    public static function criar(
        string $clienteId,
        Placa $placa,
        string $marca,
        string $modelo,
        int $ano,
        ?string $cor = null,
    ): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            clienteId: $clienteId,
            placa: $placa,
            marca: $marca,
            modelo: $modelo,
            ano: $ano,
            cor: $cor,
            criadoEm: new \DateTimeImmutable(),
        );
    }

    public function atualizar(
        string $clienteId,
        Placa $placa,
        string $marca,
        string $modelo,
        int $ano,
        ?string $cor,
    ): void {
        $this->clienteId = $clienteId;
        $this->placa = $placa;
        $this->marca = $marca;
        $this->modelo = $modelo;
        $this->ano = $ano;
        $this->cor = $cor;
    }

    public function foiPersistido(): bool
    {
        return $this->persistido;
    }

    public function marcarComoPersistido(): void
    {
        $this->persistido = true;
    }

    public function getId(): ?string        { return $this->id; }
    public function getClienteId(): string  { return $this->clienteId; }
    public function getPlaca(): Placa       { return $this->placa; }
    public function getMarca(): string      { return $this->marca; }
    public function getModelo(): string     { return $this->modelo; }
    public function getAno(): int           { return $this->ano; }
    public function getCor(): ?string       { return $this->cor; }
    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'clienteId' => $this->clienteId,
            'placa' => (string) $this->placa,
            'marca' => $this->marca,
            'modelo' => $this->modelo,
            'ano' => $this->ano,
            'cor' => $this->cor,
            'criadoEm' => $this->criadoEm->format('Y-m-d H:i:s'),
        ];
    }
}
