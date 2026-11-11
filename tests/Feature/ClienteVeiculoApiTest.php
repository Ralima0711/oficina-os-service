<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\ExigeBancoDeTeste;
use Tests\TestCase;

/**
 * Cliente e Veiculo — a decisao do ADR-0007 que ninguem tinha executado.
 *
 * "Cliente e veiculo ficam no OS Service, nao em um quarto servico: sao dados
 * do atendimento e nao tem ciclo de vida proprio que justifique o custo de
 * mais um repositorio, pipeline e banco."  (20/10)
 *
 * Ate 12/11 existiam as entidades e os value objects, e mais nada: sem tabela,
 * sem repositorio, sem rota, sem teste, 0% de cobertura — enquanto
 * ordens_servico ja guardava cliente_id e veiculo_id apontando para lugar
 * nenhum.
 *
 * Postgres de verdade, banco dedicado: as colunas sao `uuid` e ha chave
 * estrangeira entre veiculos e clientes.
 */
class ClienteVeiculoApiTest extends TestCase
{
    use ExigeBancoDeTeste;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exigirBancoDeTeste();
    }

    private function clientePf(array $troca = []): array
    {
        return array_merge([
            'nome' => 'Roberta Alves',
            'tipo' => 'pessoa_fisica',
            'documento' => '529.982.247-25',   // CPF valido
            'telefone' => '11999990000',
            'email' => 'roberta@example.com',
        ], $troca);
    }

    private function criarCliente(array $troca = []): string
    {
        return $this->postJson('/api/clientes', $this->clientePf($troca))->json('id');
    }

    // ── Cliente ───────────────────────────────────────────────────

    public function test_cadastra_cliente_e_devolve_id_uuid(): void
    {
        $r = $this->postJson('/api/clientes', $this->clientePf());

        $r->assertCreated()
            ->assertJsonPath('nome', 'Roberta Alves')
            ->assertJsonPath('documento', '529.982.247-25');

        $this->assertTrue(Uuid::isValid($r->json('id')), 'o id tem que ser uuid, como em ordens_servico');
        $this->assertDatabaseCount('clientes', 1);
    }

    /** O documento e guardado SEM mascara; o value object formata na saida. */
    public function test_documento_e_guardado_sem_mascara(): void
    {
        $this->criarCliente();

        $this->assertDatabaseHas('clientes', ['documento' => '52998224725']);
    }

    /**
     * Quem valida digito verificador e o value object Cpf, nao um regex na
     * borda: a regra mora num lugar so.
     */
    public function test_cpf_invalido_da_422(): void
    {
        $this->postJson('/api/clientes', $this->clientePf(['documento' => '111.111.111-11']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documento']);

        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_cnpj_valido_e_aceito(): void
    {
        $this->postJson('/api/clientes', $this->clientePf([
            'tipo' => 'pessoa_juridica',
            'documento' => '11.222.333/0001-81',
        ]))->assertCreated()->assertJsonPath('documento', '11.222.333/0001-81');
    }

    /** Documento e identidade natural: o mesmo CPF nao vira dois clientes. */
    public function test_documento_repetido_da_409(): void
    {
        $this->criarCliente();

        $this->postJson('/api/clientes', $this->clientePf(['nome' => 'Outra pessoa']))
            ->assertStatus(409);

        $this->assertDatabaseCount('clientes', 1);
    }

    public function test_consulta_e_lista(): void
    {
        $id = $this->criarCliente();

        $this->getJson("/api/clientes/{$id}")->assertOk()->assertJsonPath('id', $id);
        $this->getJson('/api/clientes')->assertOk()->assertJsonCount(1, 'dados');
        $this->getJson('/api/clientes/'.Uuid::uuid4()->toString())->assertStatus(404);
    }

    public function test_atualiza_cliente(): void
    {
        $id = $this->criarCliente();

        $this->putJson("/api/clientes/{$id}", $this->clientePf(['nome' => 'Roberta Lima']))
            ->assertOk()
            ->assertJsonPath('nome', 'Roberta Lima');

        $this->assertDatabaseHas('clientes', ['id' => $id, 'nome' => 'Roberta Lima']);
    }

    // ── Veiculo ───────────────────────────────────────────────────

    private function veiculo(string $clienteId, array $troca = []): array
    {
        return array_merge([
            'clienteId' => $clienteId,
            'placa' => 'ABC1D23',   // Mercosul
            'marca' => 'Fiat',
            'modelo' => 'Uno',
            'ano' => 2019,
            'cor' => 'prata',
        ], $troca);
    }

    public function test_cadastra_veiculo_ligado_ao_cliente(): void
    {
        $clienteId = $this->criarCliente();

        $r = $this->postJson('/api/veiculos', $this->veiculo($clienteId));

        $r->assertCreated()
            ->assertJsonPath('clienteId', $clienteId)
            ->assertJsonPath('placa', 'ABC1D23');

        $this->assertTrue(Uuid::isValid($r->json('id')));
    }

    public function test_placa_no_formato_antigo_tambem_vale(): void
    {
        $clienteId = $this->criarCliente();

        $this->postJson('/api/veiculos', $this->veiculo($clienteId, ['placa' => 'ABC-1234']))
            ->assertCreated()
            ->assertJsonPath('placa', 'ABC1234');
    }

    public function test_placa_invalida_da_422(): void
    {
        $clienteId = $this->criarCliente();

        $this->postJson('/api/veiculos', $this->veiculo($clienteId, ['placa' => '1234567']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['placa']);
    }

    /**
     * Cliente e veiculo vivem no mesmo servico, entao conferir aqui nao cruza
     * fronteira — e ainda ha FK no banco por baixo.
     */
    public function test_veiculo_de_cliente_inexistente_da_422(): void
    {
        $this->postJson('/api/veiculos', $this->veiculo(Uuid::uuid4()->toString()))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['clienteId']);

        $this->assertDatabaseCount('veiculos', 0);
    }

    public function test_placa_repetida_da_409(): void
    {
        $clienteId = $this->criarCliente();
        $this->postJson('/api/veiculos', $this->veiculo($clienteId))->assertCreated();

        $outro = $this->criarCliente(['documento' => '11.222.333/0001-81', 'tipo' => 'pessoa_juridica']);

        $this->postJson('/api/veiculos', $this->veiculo($outro))->assertStatus(409);

        $this->assertDatabaseCount('veiculos', 1);
    }

    public function test_lista_filtra_por_cliente(): void
    {
        $a = $this->criarCliente();
        $b = $this->criarCliente(['documento' => '11.222.333/0001-81', 'tipo' => 'pessoa_juridica']);

        $this->postJson('/api/veiculos', $this->veiculo($a, ['placa' => 'AAA1A11']))->assertCreated();
        $this->postJson('/api/veiculos', $this->veiculo($b, ['placa' => 'BBB2B22']))->assertCreated();

        $this->getJson('/api/veiculos')->assertOk()->assertJsonCount(2, 'dados');
        $this->getJson("/api/veiculos?clienteId={$a}")->assertOk()->assertJsonCount(1, 'dados');
    }

    public function test_consulta_de_veiculo_inexistente_da_404(): void
    {
        $this->getJson('/api/veiculos/'.Uuid::uuid4()->toString())->assertStatus(404);
    }
}
