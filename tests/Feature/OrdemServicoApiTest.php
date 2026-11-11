<?php

namespace Tests\Feature;

use App\Domain\OrdemServico\Entities\OrdemServico;
use App\Domain\OrdemServico\Repositories\OrdemServicoRepositoryInterface;
use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\ExigeBancoDeTeste;
use Tests\TestCase;

/**
 * A porta de entrada da saga, por HTTP.
 *
 * Ate 11/11 o OS Service nao tinha API nenhuma: sem routes/api.php, sem
 * controller, e nada em app/ chamava criar() nem iniciarSaga(). A saga so
 * disparava por tinker.
 *
 * Contra Postgres de verdade, em banco dedicado: clienteId, veiculoId e
 * mecanicoId sao colunas `uuid`, e e justamente o tipo que o SQLite nao cobra.
 */
class OrdemServicoApiTest extends TestCase
{
    use ExigeBancoDeTeste;
    use RefreshDatabase;

    private OrdemServicoRepositoryInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exigirBancoDeTeste();
        $this->repo = app(OrdemServicoRepositoryInterface::class);
    }

    private function itens(): array
    {
        return [
            ['descricao' => 'Parafuso', 'tipo' => 'peca', 'quantidade' => 2],
            ['descricao' => 'Alinhamento', 'tipo' => 'servico', 'quantidade' => 1],
        ];
    }

    private function abrir(): string
    {
        return $this->postJson('/api/ordens-servico', [
            'clienteId' => Uuid::uuid4()->toString(),
            'veiculoId' => Uuid::uuid4()->toString(),
            'descricaoProblema' => 'Barulho no freio dianteiro',
        ])->json('id');
    }

    // ── Abrir ──────────────────────────────────────────────────────

    public function test_abre_a_os_em_recebida(): void
    {
        $resposta = $this->postJson('/api/ordens-servico', [
            'clienteId' => Uuid::uuid4()->toString(),
            'veiculoId' => Uuid::uuid4()->toString(),
            'descricaoProblema' => 'Barulho no freio dianteiro',
        ]);

        $resposta->assertCreated()->assertJsonPath('status', StatusOrdem::RECEBIDA);

        $this->assertDatabaseCount('ordens_servico', 1);
        $this->assertTrue(Uuid::isValid($resposta->json('id')));
    }

    /**
     * As colunas sao `uuid`. Id invalido tem que virar 422 aqui, e nao 500
     * la embaixo quando o Postgres recusar o tipo.
     */
    public function test_id_que_nao_e_uuid_e_recusado_na_borda(): void
    {
        $this->postJson('/api/ordens-servico', [
            'clienteId' => 'c-1',
            'veiculoId' => 'DEM1A23',
            'descricaoProblema' => 'Barulho no freio',
        ])->assertStatus(422)->assertJsonValidationErrors(['clienteId', 'veiculoId']);

        $this->assertDatabaseCount('ordens_servico', 0);
    }

    // ── Fechar o diagnostico: a saga comeca aqui ──────────────────

    public function test_concluir_diagnostico_inicia_a_saga_e_grava_o_comando_no_outbox(): void
    {
        $osId = $this->abrir();

        $resposta = $this->postJson("/api/ordens-servico/{$osId}/diagnostico", [
            'mecanicoId' => Uuid::uuid4()->toString(),
            'diagnostico' => 'Pastilhas no limite e disco empenado',
            'itensDiagnosticados' => $this->itens(),
        ]);

        $resposta->assertStatus(202)
            ->assertJsonPath('status', StatusOrdem::AGUARDANDO_ORCAMENTO);

        $this->assertTrue(Uuid::isValid($resposta->json('sagaId')));

        // O comando sai pelo outbox, na mesma transacao da mudanca de estado.
        $outbox = DB::table('saga_outbox')->where('os_id', $osId)->get();
        $this->assertCount(1, $outbox);
        $this->assertSame('GerarOrcamento', $outbox[0]->tipo);
        $this->assertSame('billing.gerar-orcamento', $outbox[0]->destino);
        $this->assertNull($outbox[0]->publicado_em, 'quem publica e o worker do outbox, nao a requisicao');

        $this->assertDatabaseHas('saga_estado', ['os_id' => $osId, 'passo_atual' => 1]);
    }

    /**
     * Regra de dominio, nao capricho: orcamento zerado faria a cobranca
     * recusar e a OS ficaria presa em AGUARDANDO_PAGAMENTO para sempre.
     */
    public function test_diagnostico_sem_itens_nao_abre_saga(): void
    {
        $osId = $this->abrir();

        $this->postJson("/api/ordens-servico/{$osId}/diagnostico", [
            'mecanicoId' => Uuid::uuid4()->toString(),
            'diagnostico' => 'Nada a reparar',
            'itensDiagnosticados' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['itensDiagnosticados']);

        $this->assertDatabaseCount('saga_outbox', 0);
        $this->assertDatabaseCount('saga_estado', 0);
    }

    public function test_tipo_de_item_fora_do_contrato_e_recusado(): void
    {
        $osId = $this->abrir();

        $this->postJson("/api/ordens-servico/{$osId}/diagnostico", [
            'mecanicoId' => Uuid::uuid4()->toString(),
            'diagnostico' => 'Troca de oleo',
            'itensDiagnosticados' => [
                ['descricao' => 'Oleo', 'tipo' => 'insumo', 'quantidade' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['itensDiagnosticados.0.tipo']);
    }

    public function test_os_inexistente_da_404(): void
    {
        $this->postJson('/api/ordens-servico/'.Uuid::uuid4()->toString().'/diagnostico', [
            'mecanicoId' => Uuid::uuid4()->toString(),
            'diagnostico' => 'Pastilhas no limite',
            'itensDiagnosticados' => $this->itens(),
        ])->assertStatus(404);
    }

    /**
     * Segunda chamada e conflito com o estado, nao erro de formato: 409.
     * Sem isto, duas abas abertas abririam duas sagas para a mesma OS.
     */
    public function test_concluir_diagnostico_duas_vezes_da_409(): void
    {
        $osId = $this->abrir();

        $corpo = [
            'mecanicoId' => Uuid::uuid4()->toString(),
            'diagnostico' => 'Pastilhas no limite',
            'itensDiagnosticados' => $this->itens(),
        ];

        $this->postJson("/api/ordens-servico/{$osId}/diagnostico", $corpo)->assertStatus(202);
        $this->postJson("/api/ordens-servico/{$osId}/diagnostico", $corpo)->assertStatus(409);

        $this->assertDatabaseCount('saga_estado', 1);
        $this->assertSame(1, DB::table('saga_outbox')->where('os_id', $osId)->count());
    }

    // ── Acompanhar ────────────────────────────────────────────────

    public function test_consulta_devolve_o_estado_atual(): void
    {
        $osId = $this->abrir();

        $this->getJson("/api/ordens-servico/{$osId}")
            ->assertOk()
            ->assertJsonPath('id', $osId)
            ->assertJsonPath('status', StatusOrdem::RECEBIDA)
            ->assertJsonPath('orcamentoId', null);
    }

    public function test_consulta_de_os_inexistente_da_404(): void
    {
        $this->getJson('/api/ordens-servico/'.Uuid::uuid4()->toString())->assertStatus(404);
    }

    /**
     * O GET e como o cliente acompanha a saga depois do 202.
     */
    public function test_consulta_reflete_o_avanco_da_saga(): void
    {
        $osId = $this->abrir();

        $this->postJson("/api/ordens-servico/{$osId}/diagnostico", [
            'mecanicoId' => Uuid::uuid4()->toString(),
            'diagnostico' => 'Pastilhas no limite',
            'itensDiagnosticados' => $this->itens(),
        ])->assertStatus(202);

        // version 3, nao 2: sao DOIS saves. O controller grava o diagnostico
        // (1 -> 2) e o iniciarSaga grava o T2, concluirDiagnostico (2 -> 3).
        // A contagem importa porque e ela que o lock otimista compara.
        $this->getJson("/api/ordens-servico/{$osId}")
            ->assertOk()
            ->assertJsonPath('status', StatusOrdem::AGUARDANDO_ORCAMENTO)
            ->assertJsonPath('version', 3);
    }
}
