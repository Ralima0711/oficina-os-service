<?php

namespace Tests\Unit\Domain;

use App\Domain\OrdemServico\Entities\OrdemServico;
use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Um teste por transição legal (T1–T12) via métodos do agregado,
 * mais a regra de terminal (evento atrasado não reabre OS).
 */
final class OrdemServicoTest extends TestCase
{
    private const MECANICO = '11111111-1111-4111-8111-111111111111';
    private const ORCAMENTO = '22222222-2222-4222-8222-222222222222';
    private const PAGAMENTO = '33333333-3333-4333-8333-333333333333';

    /** Fabrica uma OS em qualquer estado, andando pela saga. */
    public static function emEstado(string $status): OrdemServico
    {
        $os = OrdemServico::criar(
            '44444444-4444-4444-8444-444444444444',
            '55555555-5555-4555-8555-555555555555',
            'Motor fazendo barulho',
        );

        $passos = [
            StatusOrdem::RECEBIDA => [],
            StatusOrdem::EM_DIAGNOSTICO => [fn ($o) => $o->iniciarDiagnostico(self::MECANICO)],
            StatusOrdem::AGUARDANDO_ORCAMENTO => [
                fn ($o) => $o->iniciarDiagnostico(self::MECANICO),
                function ($o) { $o->setDiagnostico('Falta correia'); $o->concluirDiagnostico(); },
            ],
            StatusOrdem::AGUARDANDO_APROVACAO => [
                fn ($o) => $o->iniciarDiagnostico(self::MECANICO),
                function ($o) { $o->setDiagnostico('Falta correia'); $o->concluirDiagnostico(); },
                fn ($o) => $o->registrarOrcamentoGerado(self::ORCAMENTO, 1250.0),
            ],
            StatusOrdem::APROVADA => [
                fn ($o) => $o->iniciarDiagnostico(self::MECANICO),
                function ($o) { $o->setDiagnostico('Falta correia'); $o->concluirDiagnostico(); },
                fn ($o) => $o->registrarOrcamentoGerado(self::ORCAMENTO, 1250.0),
                fn ($o) => $o->aprovar(),
            ],
            StatusOrdem::AGUARDANDO_PAGAMENTO => [
                fn ($o) => $o->iniciarDiagnostico(self::MECANICO),
                function ($o) { $o->setDiagnostico('Falta correia'); $o->concluirDiagnostico(); },
                fn ($o) => $o->registrarOrcamentoGerado(self::ORCAMENTO, 1250.0),
                fn ($o) => $o->aprovar(),
                fn ($o) => $o->iniciarCobranca(),
            ],
            StatusOrdem::EM_EXECUCAO => [
                fn ($o) => $o->iniciarDiagnostico(self::MECANICO),
                function ($o) { $o->setDiagnostico('Falta correia'); $o->concluirDiagnostico(); },
                fn ($o) => $o->registrarOrcamentoGerado(self::ORCAMENTO, 1250.0),
                fn ($o) => $o->aprovar(),
                fn ($o) => $o->iniciarCobranca(),
                fn ($o) => $o->confirmarPagamento(self::PAGAMENTO),
            ],
            StatusOrdem::FINALIZADA => [
                fn ($o) => $o->iniciarDiagnostico(self::MECANICO),
                function ($o) { $o->setDiagnostico('Falta correia'); $o->concluirDiagnostico(); },
                fn ($o) => $o->registrarOrcamentoGerado(self::ORCAMENTO, 1250.0),
                fn ($o) => $o->aprovar(),
                fn ($o) => $o->iniciarCobranca(),
                fn ($o) => $o->confirmarPagamento(self::PAGAMENTO),
                fn ($o) => $o->finalizar(),
            ],
            StatusOrdem::ENTREGUE => [
                fn ($o) => $o->iniciarDiagnostico(self::MECANICO),
                function ($o) { $o->setDiagnostico('Falta correia'); $o->concluirDiagnostico(); },
                fn ($o) => $o->registrarOrcamentoGerado(self::ORCAMENTO, 1250.0),
                fn ($o) => $o->aprovar(),
                fn ($o) => $o->iniciarCobranca(),
                fn ($o) => $o->confirmarPagamento(self::PAGAMENTO),
                fn ($o) => $o->finalizar(),
                fn ($o) => $o->entregar(),
            ],
            StatusOrdem::CANCELADA => [
                fn ($o) => $o->iniciarDiagnostico(self::MECANICO),
                function ($o) { $o->setDiagnostico('Falta correia'); $o->concluirDiagnostico(); },
                fn ($o) => $o->registrarOrcamentoGerado(self::ORCAMENTO, 1250.0),
                fn ($o) => $o->aprovar(),
                fn ($o) => $o->iniciarCobranca(),
                fn ($o) => $o->confirmarPagamento(self::PAGAMENTO),
                fn ($o) => $o->cancelarPorFalhaExecucao(),
            ],
        ];

        foreach ($passos[$status] as $passo) {
            $passo($os);
        }

        self::assertSame($status, $os->getStatus()->value());
        return $os;
    }

    private const CAMINHOS = [
        'EM_DIAGNOSTICO'       => 'iniciarDiagnostico',
        'AGUARDANDO_ORCAMENTO' => 'concluirDiagnostico',
        'AGUARDANDO_APROVACAO' => 'registrarOrcamentoGerado',
        'APROVADA'             => 'aprovar',
        'AGUARDANDO_PAGAMENTO' => 'iniciarCobranca',
        'EM_EXECUCAO'          => 'confirmarPagamento',
        'FINALIZADA'           => 'finalizar',
        'ENTREGUE'             => 'entregar',
    ];

    public static function providerCaminhoFeliz(): array
    {
        $casos = [
            'T1' => [fn () => self::emEstado(StatusOrdem::RECEBIDA), 'EM_DIAGNOSTICO', 'iniciarDiagnostico'],
            'T2' => [fn () => self::emEstado(StatusOrdem::EM_DIAGNOSTICO), 'AGUARDANDO_ORCAMENTO', 'concluirDiagnostico'],
            'T3' => [fn () => self::emEstado(StatusOrdem::AGUARDANDO_ORCAMENTO), 'AGUARDANDO_APROVACAO', 'registrarOrcamentoGerado'],
            'T4' => [fn () => self::emEstado(StatusOrdem::AGUARDANDO_ORCAMENTO), 'CANCELADA', 'cancelarPorFalhaOrcamento'],
            'T5' => [fn () => self::emEstado(StatusOrdem::AGUARDANDO_APROVACAO), 'APROVADA', 'aprovar'],
            'T6' => [fn () => self::emEstado(StatusOrdem::AGUARDANDO_APROVACAO), 'CANCELADA', 'cancelarPorRecusaOrcamento'],
            'T7' => [fn () => self::emEstado(StatusOrdem::APROVADA), 'AGUARDANDO_PAGAMENTO', 'iniciarCobranca'],
            'T8' => [fn () => self::emEstado(StatusOrdem::AGUARDANDO_PAGAMENTO), 'EM_EXECUCAO', 'confirmarPagamento'],
            'T9' => [fn () => self::emEstado(StatusOrdem::AGUARDANDO_PAGAMENTO), 'CANCELADA', 'cancelarPorFalhaPagamento'],
            'T10' => [fn () => self::emEstado(StatusOrdem::EM_EXECUCAO), 'FINALIZADA', 'finalizar'],
            'T11' => [fn () => self::emEstado(StatusOrdem::EM_EXECUCAO), 'CANCELADA', 'cancelarPorFalhaExecucao'],
            'T12' => [fn () => self::emEstado(StatusOrdem::FINALIZADA), 'ENTREGUE', 'entregar'],
        ];
        return $casos;
    }

    #[DataProvider('providerCaminhoFeliz')]
    public function testTransicaoLegalNoAgregado(callable $fabrica, string $destino, string $metodo): void
    {
        $os = $fabrica();

        if ($metodo === 'registrarOrcamentoGerado') {
            $os->$metodo(self::ORCAMENTO, 1250.0);
        } elseif ($metodo === 'confirmarPagamento') {
            $os->$metodo(self::PAGAMENTO);
        } elseif ($metodo === 'iniciarDiagnostico') {
            $os->$metodo(self::MECANICO);
        } elseif ($metodo === 'concluirDiagnostico') {
            $os->setDiagnostico('Falta correia');
            $os->$metodo();
        } else {
            $os->$metodo();
        }

        $this->assertSame($destino, $os->getStatus()->value());
    }

    public function testTerminalNaoReabre(): void
    {
        foreach ([StatusOrdem::CANCELADA, StatusOrdem::ENTREGUE] as $terminal) {
            foreach (array_values(self::CAMINHOS) as $metodo) {
                $os = self::emEstado($terminal);

                try {
                    if ($metodo === 'registrarOrcamentoGerado') {
                        $os->$metodo(self::ORCAMENTO, 1250.0);
                    } elseif ($metodo === 'confirmarPagamento') {
                        $os->$metodo(self::PAGAMENTO);
                    } elseif ($metodo === 'iniciarDiagnostico') {
                        $os->$metodo(self::MECANICO);
                    } else {
                        $os->$metodo();
                    }
                    $this->fail("Terminal {$terminal} aceitou {$metodo}");
                } catch (\DomainException) {
                    continue;
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testConcluirDiagnosticoSemDiagnosticoFalha(): void
    {
        $os = self::emEstado(StatusOrdem::EM_DIAGNOSTICO);
        $nova = OrdemServico::criar(
            '44444444-4444-4444-8444-444444444444',
            '55555555-5555-4555-8555-555555555555',
            'Motor fazendo barulho',
        );
        $nova->iniciarDiagnostico(self::MECANICO);

        $this->expectException(\DomainException::class);
        $nova->concluirDiagnostico();
    }
}
