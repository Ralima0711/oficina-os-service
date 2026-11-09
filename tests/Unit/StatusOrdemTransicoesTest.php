<?php

namespace Tests\Unit;

use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tabela de transições do docs/saga-maquina-estados.md, como teste.
 *
 * São 10 estados, logo 100 pares ordenados. Tirando os 10 de mesmo estado —
 * que não são transição, são repetição idempotente — sobram 90: 12 legais e
 * 78 ilegais. Todos os 90 são exercitados aqui.
 *
 * Os 12 legais estão escritos à mão nesta classe, NÃO lidos de
 * StatusOrdem::TRANSITIONS. É o que impede o teste de virar tautologia: se
 * alguém acrescentar uma transição na implementação, o teste reprova em vez de
 * concordar. Os 78 ilegais são derivados — tudo que não é legal.
 */
class StatusOrdemTransicoesTest extends TestCase
{
    /**
     * As 12 transições legais, com o evento que as dispara.
     * Esta lista é a fonte da verdade do teste.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function transicoesLegais(): array
    {
        return [
            'T1  RECEBIDA → EM_DIAGNOSTICO (mecânico inicia)' =>
                [StatusOrdem::RECEBIDA, StatusOrdem::EM_DIAGNOSTICO],

            'T2  EM_DIAGNOSTICO → AGUARDANDO_ORCAMENTO (diagnóstico concluído)' =>
                [StatusOrdem::EM_DIAGNOSTICO, StatusOrdem::AGUARDANDO_ORCAMENTO],

            'T3  AGUARDANDO_ORCAMENTO → AGUARDANDO_APROVACAO (orcamento.gerado)' =>
                [StatusOrdem::AGUARDANDO_ORCAMENTO, StatusOrdem::AGUARDANDO_APROVACAO],

            'T4  AGUARDANDO_ORCAMENTO → CANCELADA (orcamento.falhou)' =>
                [StatusOrdem::AGUARDANDO_ORCAMENTO, StatusOrdem::CANCELADA],

            'T5  AGUARDANDO_APROVACAO → APROVADA (orcamento.aprovado)' =>
                [StatusOrdem::AGUARDANDO_APROVACAO, StatusOrdem::APROVADA],

            'T6  AGUARDANDO_APROVACAO → CANCELADA (recusa ou expiração)' =>
                [StatusOrdem::AGUARDANDO_APROVACAO, StatusOrdem::CANCELADA],

            'T7  APROVADA → AGUARDANDO_PAGAMENTO (CobrarPagamento)' =>
                [StatusOrdem::APROVADA, StatusOrdem::AGUARDANDO_PAGAMENTO],

            'T8  AGUARDANDO_PAGAMENTO → EM_EXECUCAO (pagamento.confirmado)' =>
                [StatusOrdem::AGUARDANDO_PAGAMENTO, StatusOrdem::EM_EXECUCAO],

            'T9  AGUARDANDO_PAGAMENTO → CANCELADA (pagamento.recusado)' =>
                [StatusOrdem::AGUARDANDO_PAGAMENTO, StatusOrdem::CANCELADA],

            'T10 EM_EXECUCAO → FINALIZADA (execucao.concluida)' =>
                [StatusOrdem::EM_EXECUCAO, StatusOrdem::FINALIZADA],

            'T11 EM_EXECUCAO → CANCELADA (execucao.falhou)' =>
                [StatusOrdem::EM_EXECUCAO, StatusOrdem::CANCELADA],

            'T12 FINALIZADA → ENTREGUE (entrega ao cliente)' =>
                [StatusOrdem::FINALIZADA, StatusOrdem::ENTREGUE],
        ];
    }

    /**
     * Os 78 pares restantes. Derivados: todo par de estados diferentes que não
     * está na lista acima.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function transicoesIlegais(): array
    {
        $legais = [];
        foreach (self::transicoesLegais() as [$de, $para]) {
            $legais["{$de}→{$para}"] = true;
        }

        $casos = [];

        foreach (StatusOrdem::ALL as $de) {
            foreach (StatusOrdem::ALL as $para) {
                if ($de === $para) {
                    continue; // repetição idempotente, não transição
                }
                if (isset($legais["{$de}→{$para}"])) {
                    continue;
                }
                $casos["{$de} → {$para}"] = [$de, $para];
            }
        }

        return $casos;
    }

    // ── A contagem ─────────────────────────────────────────────────

    public function test_sao_10_estados(): void
    {
        $this->assertCount(10, StatusOrdem::ALL);
        $this->assertSame(StatusOrdem::ALL, array_unique(StatusOrdem::ALL));
    }

    public function test_sao_exatamente_12_transicoes_legais(): void
    {
        $this->assertCount(12, self::transicoesLegais());
    }

    public function test_sao_exatamente_78_transicoes_ilegais(): void
    {
        // 10 estados = 100 pares; menos os 10 de mesmo estado = 90; menos 12 legais.
        $this->assertCount(78, self::transicoesIlegais());
    }

    /**
     * A implementação não pode ser mais permissiva que esta tabela.
     * Transição acrescentada em StatusOrdem::TRANSITIONS sem passar por aqui
     * reprova neste teste.
     */
    public function test_a_implementacao_declara_as_mesmas_12_transicoes(): void
    {
        $naImplementacao = [];
        foreach (StatusOrdem::TRANSITIONS as $de => $destinos) {
            foreach ($destinos as $para) {
                $naImplementacao[] = "{$de} → {$para}";
            }
        }

        $naTabela = [];
        foreach (self::transicoesLegais() as [$de, $para]) {
            $naTabela[] = "{$de} → {$para}";
        }

        sort($naImplementacao);
        sort($naTabela);

        $this->assertSame($naTabela, $naImplementacao);
    }

    // ── Os 90 casos ────────────────────────────────────────────────

    #[DataProvider('transicoesLegais')]
    public function test_transicao_legal_e_permitida(string $de, string $para): void
    {
        $this->assertTrue(
            StatusOrdem::from($de)->podeTransitarPara($para),
            "{$de} → {$para} devia ser permitida"
        );
    }

    #[DataProvider('transicoesIlegais')]
    public function test_transicao_ilegal_e_recusada(string $de, string $para): void
    {
        $this->assertFalse(
            StatusOrdem::from($de)->podeTransitarPara($para),
            "{$de} → {$para} devia ser recusada"
        );
    }

    // ── Os casos que a revisão do contrato nomeou ──────────────────

    public function test_nao_se_executa_sem_passar_pelo_pagamento(): void
    {
        // O caso da revisão do Johny, e a Pessimistic View do ADR-0011 em teste:
        // aprovar não leva direto à execução.
        $this->assertFalse(
            StatusOrdem::from(StatusOrdem::AGUARDANDO_APROVACAO)
                ->podeTransitarPara(StatusOrdem::EM_EXECUCAO)
        );
        $this->assertFalse(
            StatusOrdem::from(StatusOrdem::APROVADA)
                ->podeTransitarPara(StatusOrdem::EM_EXECUCAO)
        );
    }

    public function test_cancelada_e_entregue_sao_terminais(): void
    {
        foreach ([StatusOrdem::CANCELADA, StatusOrdem::ENTREGUE] as $terminal) {
            $status = StatusOrdem::from($terminal);

            $this->assertTrue($status->isTerminal());

            foreach (StatusOrdem::ALL as $destino) {
                $this->assertFalse(
                    $status->podeTransitarPara($destino),
                    "{$terminal} não pode ir para {$destino}: evento atrasado não reabre OS"
                );
            }
        }
    }

    public function test_cancelada_e_alcancavel_de_todo_estado_de_espera(): void
    {
        // Compensação precisa de destino em cada passo que pode falhar.
        $esperas = [
            StatusOrdem::AGUARDANDO_ORCAMENTO,
            StatusOrdem::AGUARDANDO_APROVACAO,
            StatusOrdem::AGUARDANDO_PAGAMENTO,
            StatusOrdem::EM_EXECUCAO,
        ];

        foreach ($esperas as $estado) {
            $this->assertTrue(
                StatusOrdem::from($estado)->podeTransitarPara(StatusOrdem::CANCELADA),
                "{$estado} precisa poder cancelar: é um passo que pode falhar"
            );
        }
    }

    public function test_finalizada_nao_cancela(): void
    {
        // Reparo feito não volta atrás. O que se desfaz é dinheiro, pelo estorno.
        $this->assertFalse(
            StatusOrdem::from(StatusOrdem::FINALIZADA)->podeTransitarPara(StatusOrdem::CANCELADA)
        );
    }

    public function test_mesmo_estado_nao_e_transicao(): void
    {
        foreach (StatusOrdem::ALL as $estado) {
            $this->assertFalse(
                StatusOrdem::from($estado)->podeTransitarPara($estado),
                "{$estado} → {$estado} é repetição idempotente, não transição"
            );
        }
    }

    public function test_status_invalido_e_recusado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatusOrdem::from('AGUARDANDO_CAFE');
    }
}
