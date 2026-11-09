<?php

namespace Tests\Unit\Domain;

use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Especificação: docs/saga-maquina-estados.md, seção 3.
 * 10 estados = 100 pares. 12 legais + 78 ilegais + 10 mesmo-estado.
 */
final class StatusOrdemTest extends TestCase
{
    private const LEGAIS = [
        'RECEBIDA'             => ['EM_DIAGNOSTICO'],
        'EM_DIAGNOSTICO'       => ['AGUARDANDO_ORCAMENTO'],
        'AGUARDANDO_ORCAMENTO' => ['AGUARDANDO_APROVACAO', 'CANCELADA'],
        'AGUARDANDO_APROVACAO' => ['APROVADA', 'CANCELADA'],
        'APROVADA'             => ['AGUARDANDO_PAGAMENTO'],
        'AGUARDANDO_PAGAMENTO' => ['EM_EXECUCAO', 'CANCELADA'],
        'EM_EXECUCAO'          => ['FINALIZADA', 'CANCELADA'],
        'FINALIZADA'           => ['ENTREGUE'],
        'ENTREGUE'             => [],
        'CANCELADA'            => [],
    ];

    public static function providerTransicoesLegais(): array
    {
        $casos = [];
        foreach (self::LEGAIS as $de => $destinos) {
            foreach ($destinos as $para) {
                $casos["{$de}->{$para}"] = [$de, $para];
            }
        }
        return $casos;
    }

    public static function providerTransicoesIlegais(): array
    {
        $casos = [];
        foreach (self::LEGAIS as $de => $destinos) {
            foreach (StatusOrdem::ALL as $para) {
                if ($de === $para || in_array($para, $destinos, true)) {
                    continue;
                }
                $casos["{$de}->/{$para}"] = [$de, $para];
            }
        }
        return $casos;
    }

    public static function providerMesmoEstado(): array
    {
        $casos = [];
        foreach (StatusOrdem::ALL as $s) {
            $casos[$s] = [$s];
        }
        return $casos;
    }

    #[DataProvider('providerTransicoesLegais')]
    public function testTransicaoLegal(string $de, string $para): void
    {
        $this->assertCount(12, self::providerTransicoesLegais());
        $this->assertTrue(StatusOrdem::from($de)->podeTransitarPara($para));
    }

    #[DataProvider('providerTransicoesIlegais')]
    public function testTransicaoIlegal(string $de, string $para): void
    {
        $this->assertCount(78, self::providerTransicoesIlegais());
        $this->assertFalse(StatusOrdem::from($de)->podeTransitarPara($para));
    }

    #[DataProvider('providerMesmoEstado')]
    public function testMesmoEstadoNaoETransicao(string $estado): void
    {
        $this->assertCount(10, self::providerMesmoEstado());
        $this->assertFalse(StatusOrdem::from($estado)->podeTransitarPara($estado));
    }

    public function testTerminaisNaoTemSaida(): void
    {
        $this->assertTrue(StatusOrdem::from('CANCELADA')->isTerminal());
        $this->assertTrue(StatusOrdem::from('ENTREGUE')->isTerminal());
        $this->assertSame(
            100,
            count(self::providerTransicoesLegais())
            + count(self::providerTransicoesIlegais())
            + count(self::providerMesmoEstado()),
        );
    }
}
