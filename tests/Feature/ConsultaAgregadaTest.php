<?php

namespace Tests\Feature;

use App\Domain\OrdemServico\Entities\OrdemServico;
use App\Domain\OrdemServico\Repositories\OrdemServicoRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /api/ordens-servico/{id}/completa — API Composition.
 *
 * O que esta sob teste e o CONTRATO da composicao, nao o Billing nem a
 * Execucao: as duas chamadas HTTP sao fakes. O que importa provar e que a
 * resposta junta o que veio, e que participante fora do ar vira campo nulo
 * com motivo declarado em vez de derrubar a consulta inteira.
 *
 * Essa segunda parte e a razao de o endpoint existir desta forma. Uma consulta
 * agregada que exige os tres servicos no ar troca tres disponibilidades
 * independentes pela do pior deles — e ai a decomposicao em microsservicos
 * passa a custar sem pagar.
 */
class ConsultaAgregadaTest extends TestCase
{
    private const OS = '30ae90de-8b59-46b8-99cb-b39e94a7ef23';

    private const ORCAMENTO = '01a1d133-806f-7199-a931-556d90ecd275';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.billing.url' => 'http://billing-service',
            'services.execucao.url' => 'http://execucao-service',
            'services.composicao.falhas_para_abrir' => 3,
            'services.composicao.segundos_aberto' => 30,
        ]);

        // O disjuntor guarda estado no cache. Sem limpar, um teste que abre o
        // circuito deixaria o proximo sem chamada nenhuma — e o motivo da
        // falha seria dificil de ver.
        Cache::flush();
    }

    public function test_junta_orcamento_e_execucao_numa_resposta_so(): void
    {
        $this->comOs(orcamentoId: self::ORCAMENTO, execucaoId: 'e-1');

        Http::fake([
            'billing-service/api/orcamentos/*' => Http::response([
                'orcamentoId' => self::ORCAMENTO,
                'valorTotal' => 103.0,
                'moeda' => 'BRL',
            ]),
            'execucao-service/api/execucao/os/*/timeline' => Http::response([
                ['tipo' => 'diagnostico.iniciado', 'mecanicoId' => 'm-1'],
            ]),
        ]);

        $this->getJson('/api/ordens-servico/'.self::OS.'/completa')
            ->assertOk()
            ->assertJsonPath('id', self::OS)
            // 103.0 vira 103 no JSON, e assertJsonPath compara com ===.
            ->assertJsonPath('orcamento.valorTotal', 103)
            ->assertJsonPath('execucao.0.tipo', 'diagnostico.iniciado')
            ->assertJsonMissingPath('parciais');
    }

    public function test_billing_fora_do_ar_nao_derruba_a_consulta(): void
    {
        $this->comOs(orcamentoId: self::ORCAMENTO, execucaoId: 'e-1');

        Http::fake([
            'billing-service/*' => Http::response('', 503),
            'execucao-service/*' => Http::response([['tipo' => 'diagnostico.iniciado']]),
        ]);

        $resposta = $this->getJson('/api/ordens-servico/'.self::OS.'/completa');

        // 200, e nao 5xx: a OS e a execucao foram lidas com sucesso.
        $resposta->assertOk()
            ->assertJsonPath('orcamento', null)
            ->assertJsonPath('execucao.0.tipo', 'diagnostico.iniciado');

        $this->assertStringContainsString('billing', $resposta->json('parciais.orcamento'));
    }

    public function test_os_sem_orcamento_nao_chama_o_billing(): void
    {
        $this->comOs(orcamentoId: null, execucaoId: null);

        Http::fake([
            'execucao-service/*' => Http::response([]),
        ]);

        $resposta = $this->getJson('/api/ordens-servico/'.self::OS.'/completa')->assertOk();

        $this->assertNull($resposta->json('orcamento'));
        $this->assertSame(
            'ainda nao existe orcamento para esta OS',
            $resposta->json('parciais.orcamento')
        );

        // Nao e so que a resposta veio certa: o Billing nao foi chamado.
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'billing-service'));
    }

    public function test_timeline_vazia_e_declarada_e_nao_vira_lista_vazia(): void
    {
        $this->comOs(orcamentoId: self::ORCAMENTO, execucaoId: null);

        Http::fake([
            'billing-service/*' => Http::response(['orcamentoId' => self::ORCAMENTO]),
            'execucao-service/*' => Http::response([]),
        ]);

        $this->getJson('/api/ordens-servico/'.self::OS.'/completa')
            ->assertOk()
            ->assertJsonPath('execucao', null)
            ->assertJsonPath('parciais.execucao', 'a OS ainda nao entrou em execucao');
    }

    public function test_circuito_abre_depois_de_tres_falhas_e_para_de_chamar(): void
    {
        $this->comOs(orcamentoId: self::ORCAMENTO, execucaoId: 'e-1');

        Http::fake([
            'billing-service/*' => Http::response('', 503),
            'execucao-service/*' => Http::response([['tipo' => 'diagnostico.iniciado']]),
        ]);

        // Tres consultas, tres falhas do Billing. Na terceira o circuito abre.
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/ordens-servico/'.self::OS.'/completa')->assertOk();
        }

        $chamadasAteAqui = 3;
        $this->assertSame(
            $chamadasAteAqui,
            $this->chamadasAo('billing-service'),
            'As tres primeiras consultas tinham mesmo de tentar.'
        );

        $resposta = $this->getJson('/api/ordens-servico/'.self::OS.'/completa')->assertOk();

        // A quarta nem sai: e esse o ponto do disjuntor. A resposta continua
        // 200 e parcial, mas agora na hora, sem esperar o timeout.
        $this->assertSame($chamadasAteAqui, $this->chamadasAo('billing-service'));
        $this->assertStringContainsString(
            'circuito aberto',
            $resposta->json('parciais.orcamento')
        );

        // E a Execucao, que esta de pe, continua sendo consultada: o disjuntor
        // e por participante, nao global.
        $resposta->assertJsonPath('execucao.0.tipo', 'diagnostico.iniciado');
    }

    public function test_sucesso_fecha_o_circuito_e_zera_as_falhas(): void
    {
        $this->comOs(orcamentoId: self::ORCAMENTO, execucaoId: 'e-1');

        // Uma sequencia so, e nao tres Http::fake seguidos: chamar Http::fake
        // de novo ACRESCENTA stubs em vez de substituir, e o primeiro que casa
        // vence — o 503 continuaria respondendo para sempre.
        Http::fake([
            'billing-service/*' => Http::sequence()
                ->push('', 503)                                   // falha 1
                ->push('', 503)                                   // falha 2
                ->push(['orcamentoId' => self::ORCAMENTO], 200)   // sucesso: fecha e zera
                ->push('', 503)                                   // falha 1 de novo
                ->push('', 503),                                  // falha 2: ainda nao abre
            'execucao-service/*' => Http::response([]),
        ]);

        // Duas falhas: ainda abaixo do teto de tres.
        $this->getJson('/api/ordens-servico/'.self::OS.'/completa');
        $this->getJson('/api/ordens-servico/'.self::OS.'/completa');

        $this->getJson('/api/ordens-servico/'.self::OS.'/completa')
            ->assertOk()
            ->assertJsonPath('orcamento.orcamentoId', self::ORCAMENTO);

        // O sucesso zerou o contador. Mais duas falhas nao bastam para abrir:
        // se as antigas ainda contassem, a segunda abriria.
        $this->getJson('/api/ordens-servico/'.self::OS.'/completa');
        $resposta = $this->getJson('/api/ordens-servico/'.self::OS.'/completa')->assertOk();

        $this->assertStringNotContainsString(
            'circuito aberto',
            (string) $resposta->json('parciais.orcamento')
        );
    }

    public function test_erro_4xx_do_participante_nao_abre_o_circuito(): void
    {
        $this->comOs(orcamentoId: self::ORCAMENTO, execucaoId: 'e-1');

        Http::fake([
            'billing-service/*' => Http::response('', 422),
            'execucao-service/*' => Http::response([]),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->getJson('/api/ordens-servico/'.self::OS.'/completa')->assertOk();
        }

        // Quatro respostas 422 e o circuito segue fechado: erro de cliente e
        // problema do pedido, nao saude do participante. Abrir por causa dele
        // cortaria um servico que esta de pe.
        $this->assertSame(4, $this->chamadasAo('billing-service'));
    }

    private function chamadasAo(string $host): int
    {
        $total = 0;

        foreach (Http::recorded() as [$requisicao]) {
            if (str_contains($requisicao->url(), $host)) {
                $total++;
            }
        }

        return $total;
    }

    public function test_os_inexistente_e_404(): void
    {
        $this->app->instance(
            OrdemServicoRepositoryInterface::class,
            new RepositorioFake(null)
        );

        $this->getJson('/api/ordens-servico/'.self::OS.'/completa')->assertNotFound();
    }

    /**
     * Repositorio em memoria: a composicao nao depende de banco, e este teste
     * nao deve depender tambem. O que ele exercita e HTTP e juncao.
     */
    private function comOs(?string $orcamentoId, ?string $execucaoId): void
    {
        $os = OrdemServico::criar(
            '5e41d0c4-ef60-4d8f-8194-408f0f207733',
            '80e78948-28e7-4a2e-bebf-6da8f9b58884',
            'Barulho no freio',
        );

        $this->definir($os, 'id', self::OS);
        $this->definir($os, 'orcamentoId', $orcamentoId);
        $this->definir($os, 'execucaoId', $execucaoId);

        $this->app->instance(
            OrdemServicoRepositoryInterface::class,
            new RepositorioFake($os)
        );
    }

    private function definir(OrdemServico $os, string $propriedade, mixed $valor): void
    {
        $refl = new \ReflectionProperty($os, $propriedade);
        $refl->setValue($os, $valor);
    }
}

/**
 * Repositorio em memoria. A composicao nao toca em banco, e este teste
 * tambem nao deve: o que ele exercita e HTTP e juncao.
 */
final class RepositorioFake implements OrdemServicoRepositoryInterface
{
    public function __construct(private readonly ?OrdemServico $os) {}

    public function findById(string $id): ?OrdemServico
    {
        return $this->os;
    }

    /** @return array<int, OrdemServico> */
    public function findAll(): array
    {
        return $this->os === null ? [] : [$this->os];
    }

    public function save(OrdemServico $ordem): OrdemServico
    {
        return $ordem;
    }

    public function delete(string $id): void {}
}
