<?php

namespace Tests\Unit\Domain;

use App\Domain\OrdemServico\Entities\OrdemServico;
use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use PHPUnit\Framework\TestCase;

/**
 * toArray()/jsonSerialize() sao o formato que sai na API e nos logs: os nomes
 * das chaves sao contrato com quem consome, e os ids de correlacao (orcamento,
 * pagamento, execucao) sao o que liga a OS aos outros dois servicos.
 */
final class OrdemServicoSerializacaoTest extends TestCase
{
    private const CLIENTE = '44444444-4444-4444-8444-444444444444';
    private const VEICULO = '55555555-5555-4555-8555-555555555555';
    private const MECANICO = '11111111-1111-4111-8111-111111111111';
    private const ORCAMENTO = '22222222-2222-4222-8222-222222222222';
    private const PAGAMENTO = '33333333-3333-4333-8333-333333333333';
    private const EXECUCAO = '66666666-6666-4666-8666-666666666666';

    private function osFinalizada(): OrdemServico
    {
        $os = OrdemServico::criar(self::CLIENTE, self::VEICULO, 'Motor fazendo barulho');
        $os->iniciarDiagnostico(self::MECANICO);
        $os->setDiagnostico('Falta correia');
        $os->concluirDiagnostico();
        $os->registrarOrcamentoGerado(self::ORCAMENTO, 1250.0);
        $os->aprovar();
        $os->iniciarCobranca();
        $os->confirmarPagamento(self::PAGAMENTO);
        $os->setExecucaoId(self::EXECUCAO);
        $os->finalizar();

        return $os;
    }

    public function test_os_recem_aberta_serializa_so_o_que_ja_existe(): void
    {
        $os = OrdemServico::criar(self::CLIENTE, self::VEICULO, 'Barulho no freio');

        $dados = $os->toArray();

        $this->assertSame($os->getId(), $dados['id']);
        $this->assertSame(self::CLIENTE, $dados['cliente_id']);
        $this->assertSame(self::VEICULO, $dados['veiculo_id']);
        $this->assertSame(StatusOrdem::RECEBIDA, $dados['status']);
        $this->assertSame(1, $dados['version']);
        $this->assertSame('Barulho no freio', $dados['descricao_problema']);

        foreach (['mecanico_id', 'diagnostico', 'valor_total', 'orcamento_id', 'pagamento_id',
                     'execucao_id', 'iniciada_em', 'concluida_em', 'updated_at'] as $vazio) {
            $this->assertNull($dados[$vazio], "{$vazio} nao existe numa OS recem-aberta");
        }

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dados['created_at']);
    }

    public function test_os_finalizada_carrega_os_ids_de_correlacao_dos_outros_servicos(): void
    {
        $dados = $this->osFinalizada()->toArray();

        $this->assertSame(StatusOrdem::FINALIZADA, $dados['status']);
        $this->assertSame(self::MECANICO, $dados['mecanico_id']);
        $this->assertSame('Falta correia', $dados['diagnostico']);
        $this->assertSame(1250.0, $dados['valor_total']);
        $this->assertSame(self::ORCAMENTO, $dados['orcamento_id']);
        $this->assertSame(self::PAGAMENTO, $dados['pagamento_id']);
        $this->assertSame(self::EXECUCAO, $dados['execucao_id']);
        $this->assertNotNull($dados['concluida_em']);
    }

    public function test_json_da_os_e_o_mesmo_do_toarray(): void
    {
        $os = $this->osFinalizada();

        $this->assertSame($os->toArray(), $os->jsonSerialize());
        $this->assertSame(json_encode($os->toArray()), json_encode($os));
    }

    public function test_incrementar_version_aparece_na_serializacao(): void
    {
        $os = OrdemServico::criar(self::CLIENTE, self::VEICULO, 'x');
        $os->incrementVersion();
        $os->incrementVersion();

        $this->assertSame(3, $os->toArray()['version']);
    }
}
