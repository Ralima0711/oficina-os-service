<?php

namespace Tests\Unit;

use App\Domain\OrdemServico\Entities\OrdemServico;
use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tabela de transições vista pela entidade.
 *
 * O StatusOrdemTransicoesTest prova que a TABELA está certa. Este prova que os
 * MÉTODOS da entidade a respeitam — que `aprovar()` só funciona em
 * AGUARDANDO_APROVACAO, e assim por diante. São coisas diferentes: a tabela
 * pode estar certa e um método chamar a transição errada.
 */
class OrdemServicoTransicoesTest extends TestCase
{
    private function osEm(string $status): OrdemServico
    {
        $os = OrdemServico::criar(
            clienteId: '11111111-1111-4111-8111-111111111111',
            veiculoId: 'DEM1A23',
            descricaoProblema: 'Barulho no freio',
        );

        // Caminha até o estado pedido pelos métodos legais, para o objeto ficar
        // consistente — e não forçado por reflexão.
        if ($status === StatusOrdem::RECEBIDA) {
            return $os;
        }

        $os->iniciarDiagnostico('22222222-2222-4222-8222-222222222222');
        if ($status === StatusOrdem::EM_DIAGNOSTICO) {
            return $os;
        }

        $os->setDiagnostico('Pastilhas no limite');
        $os->concluirDiagnostico();
        if ($status === StatusOrdem::AGUARDANDO_ORCAMENTO) {
            return $os;
        }

        if ($status === StatusOrdem::CANCELADA) {
            $os->cancelarPorFalhaOrcamento();
            return $os;
        }

        $os->registrarOrcamentoGerado('33333333-3333-4333-8333-333333333333', 241.80);
        if ($status === StatusOrdem::AGUARDANDO_APROVACAO) {
            return $os;
        }

        $os->aprovar();
        if ($status === StatusOrdem::APROVADA) {
            return $os;
        }

        $os->iniciarCobranca();
        if ($status === StatusOrdem::AGUARDANDO_PAGAMENTO) {
            return $os;
        }

        $os->confirmarPagamento('44444444-4444-4444-8444-444444444444');
        if ($status === StatusOrdem::EM_EXECUCAO) {
            return $os;
        }

        $os->finalizar();
        if ($status === StatusOrdem::FINALIZADA) {
            return $os;
        }

        $os->entregar();
        return $os;
    }

    // ── O caminho feliz inteiro ────────────────────────────────────

    public function test_caminho_feliz_percorre_os_dez_estados(): void
    {
        $os = OrdemServico::criar('c-1', 'DEM1A23', 'Barulho no freio');
        $this->assertSame(StatusOrdem::RECEBIDA, $os->getStatus()->value());

        $os->iniciarDiagnostico('m-1');
        $this->assertSame(StatusOrdem::EM_DIAGNOSTICO, $os->getStatus()->value());

        $os->setDiagnostico('Pastilhas no limite');
        $os->concluirDiagnostico();
        $this->assertSame(StatusOrdem::AGUARDANDO_ORCAMENTO, $os->getStatus()->value());

        $os->registrarOrcamentoGerado('o-1', 241.80);
        $this->assertSame(StatusOrdem::AGUARDANDO_APROVACAO, $os->getStatus()->value());
        $this->assertSame('o-1', $os->getOrcamentoId());
        $this->assertSame(241.80, $os->getValorTotal());

        $os->aprovar();
        $this->assertSame(StatusOrdem::APROVADA, $os->getStatus()->value());

        $os->iniciarCobranca();
        $this->assertSame(StatusOrdem::AGUARDANDO_PAGAMENTO, $os->getStatus()->value());

        $os->confirmarPagamento('p-1');
        $this->assertSame(StatusOrdem::EM_EXECUCAO, $os->getStatus()->value());
        $this->assertSame('p-1', $os->getPagamentoId());

        $os->finalizar();
        $this->assertSame(StatusOrdem::FINALIZADA, $os->getStatus()->value());
        $this->assertNotNull($os->getConcluidaEm());

        $os->entregar();
        $this->assertSame(StatusOrdem::ENTREGUE, $os->getStatus()->value());
    }

    // ── Cada método só funciona de onde deve ───────────────────────

    /**
     * Método da entidade → estado de origem em que ele é legal.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function metodosEOrigemLegal(): array
    {
        return [
            'iniciarDiagnostico' => ['iniciarDiagnostico', StatusOrdem::RECEBIDA],
            'concluirDiagnostico' => ['concluirDiagnostico', StatusOrdem::EM_DIAGNOSTICO],
            'registrarOrcamentoGerado' => ['registrarOrcamentoGerado', StatusOrdem::AGUARDANDO_ORCAMENTO],
            'cancelarPorFalhaOrcamento' => ['cancelarPorFalhaOrcamento', StatusOrdem::AGUARDANDO_ORCAMENTO],
            'aprovar' => ['aprovar', StatusOrdem::AGUARDANDO_APROVACAO],
            'cancelarPorRecusaOrcamento' => ['cancelarPorRecusaOrcamento', StatusOrdem::AGUARDANDO_APROVACAO],
            'iniciarCobranca' => ['iniciarCobranca', StatusOrdem::APROVADA],
            'confirmarPagamento' => ['confirmarPagamento', StatusOrdem::AGUARDANDO_PAGAMENTO],
            'cancelarPorFalhaPagamento' => ['cancelarPorFalhaPagamento', StatusOrdem::AGUARDANDO_PAGAMENTO],
            'finalizar' => ['finalizar', StatusOrdem::EM_EXECUCAO],
            'cancelarPorFalhaExecucao' => ['cancelarPorFalhaExecucao', StatusOrdem::EM_EXECUCAO],
            'entregar' => ['entregar', StatusOrdem::FINALIZADA],
        ];
    }

    private function argumentos(string $metodo): array
    {
        return match ($metodo) {
            'iniciarDiagnostico' => ['m-1'],
            'registrarOrcamentoGerado' => ['o-1', 100.0],
            'confirmarPagamento' => ['p-1'],
            default => [],
        };
    }

    #[DataProvider('metodosEOrigemLegal')]
    public function test_metodo_funciona_a_partir_da_origem_legal(string $metodo, string $origem): void
    {
        $os = $this->osEm($origem);

        if ($metodo === 'concluirDiagnostico') {
            $os->setDiagnostico('Pastilhas no limite');
        }

        $os->{$metodo}(...$this->argumentos($metodo));

        $this->assertNotSame($origem, $os->getStatus()->value());
    }

    #[DataProvider('metodosEOrigemLegal')]
    public function test_metodo_recusa_a_partir_de_qualquer_outro_estado(string $metodo, string $origem): void
    {
        $outros = array_filter(
            StatusOrdem::ALL,
            fn (string $estado) => $estado !== $origem
        );

        foreach ($outros as $estado) {
            $os = $this->osEm($estado);

            if ($metodo === 'concluirDiagnostico') {
                $os->setDiagnostico('qualquer coisa');
            }

            try {
                $os->{$metodo}(...$this->argumentos($metodo));
                $this->fail("{$metodo}() devia falhar a partir de {$estado}, e passou");
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    // ── Guardas que não são só de estado ───────────────────────────

    public function test_nao_conclui_diagnostico_sem_diagnostico(): void
    {
        $os = $this->osEm(StatusOrdem::EM_DIAGNOSTICO);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Diagnóstico deve ser informado');

        $os->concluirDiagnostico();
    }

    public function test_nao_inicia_diagnostico_sem_mecanico(): void
    {
        $os = $this->osEm(StatusOrdem::RECEBIDA);

        $this->expectException(\DomainException::class);

        $os->iniciarDiagnostico('   ');
    }

    public function test_os_nasce_na_versao_1(): void
    {
        $this->assertSame(1, OrdemServico::criar('c-1', 'DEM1A23', 'x')->getVersion());
    }

    public function test_a_mensagem_do_erro_nomeia_os_dois_estados(): void
    {
        // Quem lê o log precisa saber de onde para onde, não só que falhou.
        $os = $this->osEm(StatusOrdem::RECEBIDA);

        try {
            $os->finalizar();
            $this->fail('devia ter falhado');
        } catch (\DomainException $e) {
            $this->assertStringContainsString(StatusOrdem::RECEBIDA, $e->getMessage());
            $this->assertStringContainsString(StatusOrdem::FINALIZADA, $e->getMessage());
        }
    }
}
