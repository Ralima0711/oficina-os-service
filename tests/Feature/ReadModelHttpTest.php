<?php

namespace Tests\Feature;

use App\Application\Messaging\Envelope;
use App\Application\ReadModel\ProjetorDeOrdens;
use App\Application\ReadModel\RepositorioDeLeituraInterface;
use Tests\Support\RepositorioDeLeituraEmMemoria;
use Tests\TestCase;

/**
 * GET /api/ordens-servico/{id}/historico.
 *
 * A rota chegou sem teste de HTTP nenhum: havia teste do projetor (em
 * memoria) e do adaptador Mongo (integracao, pulado sem banco), e nada entre
 * a rota e a resposta. Era o unico endpoint do servico nessa situacao.
 *
 * Usa o repositorio em memoria de proposito: o que esta sob teste aqui e o
 * contrato HTTP — rota, 404 e forma da resposta — nao o driver do Mongo.
 */
class ReadModelHttpTest extends TestCase
{
    private const OS = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';

    private const SAGA = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';

    private RepositorioDeLeituraEmMemoria $leitura;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leitura = new RepositorioDeLeituraEmMemoria;
        $this->app->instance(RepositorioDeLeituraInterface::class, $this->leitura);
    }

    public function test_devolve_404_enquanto_nada_foi_projetado(): void
    {
        $this->getJson('/api/ordens-servico/'.self::OS.'/historico')
            ->assertNotFound()
            ->assertJsonStructure(['erro']);
    }

    public function test_devolve_status_historico_e_os_campos_de_atraso(): void
    {
        $projetor = new ProjetorDeOrdens($this->leitura);

        $projetor->aplicar($this->evento('orcamento.gerado'));
        $ultimo = $this->evento('orcamento.aprovado');
        $projetor->aplicar($ultimo);

        $resposta = $this->getJson('/api/ordens-servico/'.self::OS.'/historico');

        $resposta->assertOk()
            ->assertJsonPath('osId', self::OS)
            ->assertJsonPath('status', 'APROVADA')
            ->assertJsonPath('sagaId', self::SAGA)
            ->assertJsonPath('ultimoEventoId', $ultimo->getMessageId())
            ->assertJsonCount(2, 'historico')
            ->assertJsonPath('historico.0.tipo', 'orcamento.gerado')
            ->assertJsonPath('historico.1.tipo', 'orcamento.aprovado');

        // O campo chamava-se commandId e prometia uma comparacao que o cliente
        // nao tinha como fazer: o 202 do POST /diagnostico devolve sagaId, nao
        // messageId. Se ele voltar, este teste cai.
        $resposta->assertJsonMissingPath('commandId');
    }

    public function test_evento_sem_status_nao_apaga_o_status_ja_projetado(): void
    {
        $projetor = new ProjetorDeOrdens($this->leitura);

        $projetor->aplicar($this->evento('orcamento.aprovado'));
        $projetor->aplicar($this->evento('evento.sem.status.mapeado'));

        $this->getJson('/api/ordens-servico/'.self::OS.'/historico')
            ->assertOk()
            ->assertJsonPath('status', 'APROVADA')
            ->assertJsonCount(2, 'historico');
    }

    public function test_reentrega_do_mesmo_evento_nao_duplica_historico(): void
    {
        $projetor = new ProjetorDeOrdens($this->leitura);
        $evento = $this->evento('orcamento.gerado');

        $projetor->aplicar($evento);
        $projetor->aplicar($evento);

        $this->getJson('/api/ordens-servico/'.self::OS.'/historico')
            ->assertOk()
            ->assertJsonCount(1, 'historico');
    }

    private function evento(string $tipo): Envelope
    {
        return Envelope::create(
            type: $tipo,
            body: ['osId' => self::OS],
            sagaId: self::SAGA,
        );
    }
}
