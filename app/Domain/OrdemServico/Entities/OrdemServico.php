<?php

namespace App\Domain\OrdemServico\Entities;

use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * CAMADA DE DOMÍNIO — Entidade
 * Agregado raiz da Ordem de Serviço.
 * Contém as transições de estado da saga (docs/saga-maquina-estados.md).
 * NÃO depende de framework, banco de dados ou infraestrutura.
 */
class OrdemServico implements \JsonSerializable
{
    public function __construct(
        private ?string $id,
        private string $clienteId,
        private string $veiculoId,
        private ?string $mecanicoId,
        private StatusOrdem $status,
        private int $version,
        private string $descricaoProblema,
        private ?string $diagnostico = null,
        private ?float $valorTotal = null,
        private ?string $orcamentoId = null,
        private ?string $pagamentoId = null,
        private ?string $execucaoId = null,
        private ?\DateTimeImmutable $iniciadaEm = null,
        private ?\DateTimeImmutable $criadaEm = null,
        private ?\DateTimeImmutable $atualizadaEm = null,
        private ?\DateTimeImmutable $concluidaEm = null,
    ) {}

    // ── Factory ────────────────────────────────────────────────────

    public static function criar(
        string $clienteId,
        string $veiculoId,
        string $descricaoProblema,
    ): self {
        return new self(
            id: Uuid::uuid4()->toString(),
            clienteId: $clienteId,
            veiculoId: $veiculoId,
            mecanicoId: null,
            status: StatusOrdem::from(StatusOrdem::RECEBIDA),
            version: 1,
            descricaoProblema: $descricaoProblema,
            criadaEm: new \DateTimeImmutable(),
        );
    }

    // ── Transições legais (T1–T12) ─────────────────────────────────

    /** T1: RECEBIDA → EM_DIAGNOSTICO */
    public function iniciarDiagnostico(string $mecanicoId): void
    {
        $this->transitarPara(StatusOrdem::EM_DIAGNOSTICO);
        if (trim($mecanicoId) === '') {
            throw new \DomainException('Mecânico inválido para iniciar diagnóstico.');
        }
        $this->mecanicoId = $mecanicoId;
        $this->iniciadaEm ??= new \DateTimeImmutable();
    }

    /** T2: EM_DIAGNOSTICO → AGUARDANDO_ORCAMENTO */
    public function concluirDiagnostico(): void
    {
        if ($this->diagnostico === null || trim($this->diagnostico) === '') {
            throw new \DomainException('Diagnóstico deve ser informado antes de gerar orçamento.');
        }
        $this->transitarPara(StatusOrdem::AGUARDANDO_ORCAMENTO);
    }

    /** T3: AGUARDANDO_ORCAMENTO → AGUARDANDO_APROVACAO (orcamento.gerado) */
    public function registrarOrcamentoGerado(string $orcamentoId, float $valorTotal): void
    {
        $this->transitarPara(StatusOrdem::AGUARDANDO_APROVACAO);
        $this->orcamentoId = $orcamentoId;
        $this->valorTotal = $valorTotal;
    }

    /** T4: AGUARDANDO_ORCAMENTO → CANCELADA (orcamento.falhou) */
    public function cancelarPorFalhaOrcamento(): void
    {
        $this->exigirOrigem(StatusOrdem::AGUARDANDO_ORCAMENTO, 'cancelarPorFalhaOrcamento');
        $this->transitarPara(StatusOrdem::CANCELADA);
    }

    /** T5: AGUARDANDO_APROVACAO → APROVADA (orcamento.aprovado) */
    public function aprovar(): void
    {
        $this->transitarPara(StatusOrdem::APROVADA);
    }

    /** T6: AGUARDANDO_APROVACAO → CANCELADA (orcamento.recusado / aprovacao.expirada) */
    public function cancelarPorRecusaOrcamento(): void
    {
        $this->exigirOrigem(StatusOrdem::AGUARDANDO_APROVACAO, 'cancelarPorRecusaOrcamento');
        $this->transitarPara(StatusOrdem::CANCELADA);
    }

    /** T7: APROVADA → AGUARDANDO_PAGAMENTO (CobrarPagamento publicado) */
    public function iniciarCobranca(): void
    {
        $this->transitarPara(StatusOrdem::AGUARDANDO_PAGAMENTO);
    }

    /** T8: AGUARDANDO_PAGAMENTO → EM_EXECUCAO (pagamento.confirmado) */
    public function confirmarPagamento(string $pagamentoId): void
    {
        $this->transitarPara(StatusOrdem::EM_EXECUCAO);
        $this->pagamentoId = $pagamentoId;
    }

    /** T9: AGUARDANDO_PAGAMENTO → CANCELADA (pagamento.recusado) */
    public function cancelarPorFalhaPagamento(): void
    {
        $this->exigirOrigem(StatusOrdem::AGUARDANDO_PAGAMENTO, 'cancelarPorFalhaPagamento');
        $this->transitarPara(StatusOrdem::CANCELADA);
    }

    /** T10: EM_EXECUCAO → FINALIZADA (execucao.concluida) */
    public function finalizar(): void
    {
        $this->transitarPara(StatusOrdem::FINALIZADA);
        $this->concluidaEm = new \DateTimeImmutable();
    }

    /** T11: EM_EXECUCAO → CANCELADA (execucao.falhou) */
    public function cancelarPorFalhaExecucao(): void
    {
        $this->exigirOrigem(StatusOrdem::EM_EXECUCAO, 'cancelarPorFalhaExecucao');
        $this->transitarPara(StatusOrdem::CANCELADA);
    }

    /** T12: FINALIZADA → ENTREGUE */
    public function entregar(): void
    {
        $this->transitarPara(StatusOrdem::ENTREGUE);
    }

    // ── Validação de transição ─────────────────────────────────────

    private function transitarPara(string $destino): void
    {
        if (!$this->status->podeTransitarPara($destino)) {
            throw new \DomainException(
                "Transição ilegal: {$this->status->value()} → {$destino}"
            );
        }
        $this->status = StatusOrdem::from($destino);
    }

    // ── Getters ────────────────────────────────────────────────────

    public function getId(): ?string            { return $this->id; }
    public function getClienteId(): string      { return $this->clienteId; }
    public function getVeiculoId(): string      { return $this->veiculoId; }
    public function getMecanicoId(): ?string    { return $this->mecanicoId; }
    public function getStatus(): StatusOrdem    { return $this->status; }
    public function getVersion(): int           { return $this->version; }
    public function getDescricao(): string      { return $this->descricaoProblema; }
    public function getDiagnostico(): ?string   { return $this->diagnostico; }
    public function getValorTotal(): ?float     { return $this->valorTotal; }
    public function getOrcamentoId(): ?string   { return $this->orcamentoId; }
    public function getPagamentoId(): ?string   { return $this->pagamentoId; }
    public function getExecucaoId(): ?string    { return $this->execucaoId; }
    public function getIniciadaEm(): ?\DateTimeImmutable { return $this->iniciadaEm; }
    public function getCriadaEm(): ?\DateTimeImmutable { return $this->criadaEm; }
    public function getAtualizadaEm(): ?\DateTimeImmutable { return $this->atualizadaEm; }
    public function getConcluidaEm(): ?\DateTimeImmutable { return $this->concluidaEm; }

    public function setDiagnostico(string $diagnostico): void
    {
        $this->diagnostico = $diagnostico;
    }

    public function setExecucaoId(string $execucaoId): void
    {
        $this->execucaoId = $execucaoId;
    }

    public function incrementVersion(): void
    {
        $this->version++;
    }

    // ── Serialização ───────────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cliente_id' => $this->clienteId,
            'veiculo_id' => $this->veiculoId,
            'mecanico_id' => $this->mecanicoId,
            'status' => $this->status->value(),
            'version' => $this->version,
            'descricao_problema' => $this->descricaoProblema,
            'diagnostico' => $this->diagnostico,
            'valor_total' => $this->valorTotal,
            'orcamento_id' => $this->orcamentoId,
            'pagamento_id' => $this->pagamentoId,
            'execucao_id' => $this->execucaoId,
            'iniciada_em' => $this->iniciadaEm?->format('Y-m-d H:i:s'),
            'concluida_em' => $this->concluidaEm?->format('Y-m-d H:i:s'),
            'created_at' => $this->criadaEm?->format('Y-m-d H:i:s'),
            'updated_at' => $this->atualizadaEm?->format('Y-m-d H:i:s'),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function exigirOrigem(string $esperado, string $metodo): void
    {
        if (!$this->status->equals($esperado)) {
            throw new \DomainException(
                "{$metodo}() exige origem {$esperado}, mas a OS esta em {$this->status->value()}"
            );
        }
    }
}
