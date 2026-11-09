<?php

namespace App\Domain\OrdemServico\ValueObjects;

/**
 * CAMADA DE DOMÍNIO — Value Object
 * Imutável. Sem identidade própria — seu valor É sua identidade.
 *
 * 10 estados da OS (Fase 4 — contrato v1.1, Parte 3).
 * A tabela de transições legais está em TRANSITIONS.
 * Tudo que não está na tabela é ilegal — ver podeTransitarPara().
 */
final class StatusOrdem
{
    public const RECEBIDA = 'RECEBIDA';
    public const EM_DIAGNOSTICO = 'EM_DIAGNOSTICO';
    public const AGUARDANDO_ORCAMENTO = 'AGUARDANDO_ORCAMENTO';
    public const AGUARDANDO_APROVACAO = 'AGUARDANDO_APROVACAO';
    public const APROVADA = 'APROVADA';
    public const AGUARDANDO_PAGAMENTO = 'AGUARDANDO_PAGAMENTO';
    public const EM_EXECUCAO = 'EM_EXECUCAO';
    public const FINALIZADA = 'FINALIZADA';
    public const ENTREGUE = 'ENTREGUE';
    public const CANCELADA = 'CANCELADA';

    public const ALL = [
        self::RECEBIDA,
        self::EM_DIAGNOSTICO,
        self::AGUARDANDO_ORCAMENTO,
        self::AGUARDANDO_APROVACAO,
        self::APROVADA,
        self::AGUARDANDO_PAGAMENTO,
        self::EM_EXECUCAO,
        self::FINALIZADA,
        self::ENTREGUE,
        self::CANCELADA,
    ];

    /**
     * Transições legais — espelha a tabela do docs/saga-maquina-estados.md.
     * Chave = estado de origem; valor = estados de destino permitidos.
     */
    public const TRANSITIONS = [
        self::RECEBIDA               => [self::EM_DIAGNOSTICO],
        self::EM_DIAGNOSTICO         => [self::AGUARDANDO_ORCAMENTO],
        self::AGUARDANDO_ORCAMENTO   => [self::AGUARDANDO_APROVACAO, self::CANCELADA],
        self::AGUARDANDO_APROVACAO   => [self::APROVADA, self::CANCELADA],
        self::APROVADA               => [self::AGUARDANDO_PAGAMENTO],
        self::AGUARDANDO_PAGAMENTO   => [self::EM_EXECUCAO, self::CANCELADA],
        self::EM_EXECUCAO            => [self::FINALIZADA, self::CANCELADA],
        self::FINALIZADA             => [self::ENTREGUE],
        self::ENTREGUE               => [],
        self::CANCELADA              => [],
    ];

    private function __construct(private string $value) {}

    public static function from(string $value): self
    {
        if (!in_array($value, self::ALL, true)) {
            throw new \InvalidArgumentException("Status inválido: {$value}");
        }
        return new self($value);
    }

    public static function __callStatic(string $name, array $args): self
    {
        return self::from($name);
    }

    public function equals(string $other): bool
    {
        return $this->value === $other;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function podeTransitarPara(string $destino): bool
    {
        $permitidos = self::TRANSITIONS[$this->value] ?? [];
        return in_array($destino, $permitidos, true);
    }

    public function isTerminal(): bool
    {
        return $this->value === self::CANCELADA || $this->value === self::ENTREGUE;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
