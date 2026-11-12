<?php

namespace Tests\Integration;

use App\Application\Messaging\Envelope;
use App\Application\ReadModel\ProjetorDeOrdens;
use App\Infrastructure\ReadModel\MongoRepositorioDeLeitura;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Read model contra MongoDB de verdade.
 *
 * Os testes unitários do projetor usam repositório em memória: eles provam a
 * tradução evento → documento, mas não provam que o adaptador Mongo funciona.
 * Este teste prova o adaptador — inclusive o push de histórico e a idempotência
 * por messageId, que dependem do driver.
 *
 * Pula sozinho quando não há Mongo acessível, para a suíte não quebrar em
 * máquina sem o banco. Quando roda, limpa só a coleção do read model.
 */
final class ReadModelMongoTest extends TestCase
{
    private const OS = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
    private const SAGA = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
    private const COLECAO = 'ordens_servico_view';

    private MongoRepositorioDeLeitura $repositorio;
    private ProjetorDeOrdens $projetor;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('mongodb')->table(self::COLECAO)->delete();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'MongoDB indisponivel para o read model: ' . $e->getMessage()
            );
        }

        $this->repositorio = new MongoRepositorioDeLeitura();
        $this->projetor = new ProjetorDeOrdens($this->repositorio);
    }

    private function envelope(string $tipo, array $body = []): Envelope
    {
        return Envelope::create(
            type: $tipo,
            body: $body + ['osId' => self::OS],
            sagaId: self::SAGA,
        );
    }

    public function testProjetaDocumentoNoMongo(): void
    {
        $this->projetor->aplicar($this->envelope('orcamento.gerado'));

        $doc = $this->repositorio->buscarPorOsId(self::OS);

        $this->assertNotNull($doc);
        $this->assertSame('AGUARDANDO_APROVACAO', $doc['status']);
        $this->assertSame(self::SAGA, $doc['sagaId']);
        $this->assertCount(1, $doc['historico']);
    }

    public function testHistoricoAcumulaNoMongo(): void
    {
        foreach (['os.aberta', 'orcamento.gerado', 'orcamento.aprovado'] as $tipo) {
            $this->projetor->aplicar($this->envelope($tipo));
        }

        $doc = $this->repositorio->buscarPorOsId(self::OS);

        $this->assertSame('APROVADA', $doc['status']);
        $this->assertSame(
            ['os.aberta', 'orcamento.gerado', 'orcamento.aprovado'],
            array_column($doc['historico'], 'tipo'),
        );
    }

    public function testReentregaNaoDuplicaNoMongo(): void
    {
        $env = $this->envelope('orcamento.gerado');

        $this->projetor->aplicar($env);
        $this->projetor->aplicar($env);

        $doc = $this->repositorio->buscarPorOsId(self::OS);

        $this->assertCount(1, $doc['historico']);
        $this->assertCount(1, $doc['messageIds']);
    }

    public function testListarDevolveOsProjetados(): void
    {
        $this->projetor->aplicar($this->envelope('os.aberta'));

        $lista = $this->repositorio->listar(10);

        $this->assertNotEmpty($lista);
        $this->assertSame(self::OS, $lista[0]['osId']);
    }
}
