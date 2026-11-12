<?php

namespace App\Domain\Cliente\Entities;

use App\Domain\Cliente\ValueObjects\DocumentoFiscal;
use Ramsey\Uuid\Uuid;

/**
 * CAMADA DE DOMINIO — Entidade Cliente
 *
 * O ADR-0007 coloca cliente e veiculo no OS Service: "sao dados do atendimento
 * e nao tem ciclo de vida proprio que justifique o custo de mais um
 * repositorio, pipeline e banco". A decisao estava registrada desde 20/10 e o
 * recorte parou no meio — a entidade existia, sem repositorio, sem rota e sem
 * teste. Isto fecha o recorte.
 *
 * A identidade virou uuid. Na Fase 3 era auto-incremento, mas em Fase 4
 * ordens_servico.cliente_id e coluna `uuid` e o OrdemServico ja gera uuid4 na
 * criacao. Identidade inteira aqui e uuid la obrigaria a converter na
 * fronteira, que e onde erro de tipo se esconde.
 */
class Cliente implements \JsonSerializable
{
    private bool $persistido = false;

    public function __construct(
        private ?string $id,
        private string $nome,
        private string $tipo,
        private DocumentoFiscal $documento,
        private string $telefone,
        private string $email,
        private \DateTimeImmutable $criadoEm,
    ) {}

    /** Identidade nasce com o objeto, como no OrdemServico. */
    public static function criar(
        string $nome,
        string $tipo,
        DocumentoFiscal $documento,
        string $telefone,
        string $email,
    ): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            nome: $nome,
            tipo: $tipo,
            documento: $documento,
            telefone: $telefone,
            email: $email,
            criadoEm: new \DateTimeImmutable(),
        );
    }

    public function atualizar(
        string $nome,
        string $tipo,
        DocumentoFiscal $documento,
        string $telefone,
        string $email,
    ): void {
        $this->nome = $nome;
        $this->tipo = $tipo;
        $this->documento = $documento;
        $this->telefone = $telefone;
        $this->email = $email;
    }

    /**
     * Ja existe linha no banco? NAO da para usar getId() === null: criar()
     * gera o uuid na hora. Foi exatamente esse engano que deixou o insert() do
     * EloquentOrdemServicoRepository inalcancavel ate 11/11.
     */
    public function foiPersistido(): bool
    {
        return $this->persistido;
    }

    public function marcarComoPersistido(): void
    {
        $this->persistido = true;
    }

    public function getId(): ?string  { return $this->id; }
    public function getNome(): string { return $this->nome; }
    public function getTipo(): string { return $this->tipo; }
    public function getDocumento(): DocumentoFiscal { return $this->documento; }
    public function getTelefone(): string { return $this->telefone; }
    public function getEmail(): string    { return $this->email; }
    public function getCriadoEm(): \DateTimeImmutable { return $this->criadoEm; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'tipo' => $this->tipo,
            'documento' => (string) $this->documento,
            'documentoRaw' => $this->documento->getRaw(),
            'telefone' => $this->telefone,
            'email' => $this->email,
            'criadoEm' => $this->criadoEm->format('Y-m-d H:i:s'),
        ];
    }
}
