<?php

namespace Tests\Unit\ReadModel;

use App\Application\Messaging\Envelope;
use App\Application\ReadModel\ProjetorDeOrdens;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RepositorioDeLeituraEmMemoria;
use Tests\TestCase;

final class ProjetorDeOrdensTest extends TestCase
{
    private const OS = '11111111-1111-4111-8111-111111111111';
    private const SAGA = '22222222-2222-4222-8222-222222222222';

    private RepositorioDeLeituraEmMemoria $repositorio;
    private ProjetorDeOrdens $projetor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositorio = new RepositorioDeLeituraEmMemoria();
        $this->projetor = new ProjetorDeOrdens($this->repositorio);
    }

    private function envelope(string $tipo, array $body = [], ?string $messageId = null): Envelope
    {
        return Envelope::create(
            type: $tipo,
            body: $body + ['osId' => self::OS],
            sagaId: self::SAGA,
            causationId: null,
            traceparent: '00-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01',
        );
    }

    public static function providerStatusPorEvento(): array
    {
        $casos = [];
        foreach (ProjetorDeOrdens::STATUS_POR_EVENTO as $tipo => $status) {
            $casos[$tipo] = [$tipo, $status];
        }
        return $casos;
    }

    #[DataProvider('providerStatusPorEvento')]
    public function testProjetaStatusDoEvento(string $tipo, string $status): void
    {
        $this->projetor->aplicar($this->envelope($tipo));

        $doc = $this->repositorio->buscarPorOsId(self::OS);

        $this->assertNotNull($doc);
        $this->assertSame($status, $doc['status']);
        $this->assertCount(1, $doc['historico']);
        $this->assertSame($tipo, $doc['historico'][0]['tipo']);
    }

    public function testHistoricoAcumulaNaOrdemDosEventos(): void
    {
        foreach (['os.aberta', 'orcamento.gerado', 'orcamento.aprovado', 'pagamento.confirmado', 'execucao.concluida'] as $tipo) {
            $this->projetor->aplicar($this->envelope($tipo));
        }

        $doc = $this->repositorio->buscarPorOsId(self::OS);

        $this->assertSame('FINALIZADA', $doc['status']);
        $this->assertSame(
            ['os.aberta', 'orcamento.gerado', 'orcamento.aprovado', 'pagamento.confirmado', 'execucao.concluida'],
            array_column($doc['historico'], 'tipo'),
        );
    }

    public function testReentregaNaoDuplicaHistorico(): void
    {
        $env = $this->envelope('orcamento.gerado');

        $this->projetor->aplicar($env);
        $this->projetor->aplicar($env);

        $doc = $this->repositorio->buscarPorOsId(self::OS);

        $this->assertCount(1, $doc['historico']);
        $this->assertCount(1, $doc['messageIds']);
    }

    public function testEventoSemOsIdNaoCriaDocumento(): void
    {
        $env = Envelope::create(
            type: 'orcamento.gerado',
            body: ['orcamentoId' => '33333333-3333-4333-8333-333333333333'],
            sagaId: self::SAGA,
        );

        $this->projetor->aplicar($env);

        $this->assertSame([], $this->repositorio->documentos);
    }

    public function testEventoForaDoMapaEntraNoHistoricoSemMudarStatus(): void
    {
        $this->projetor->aplicar($this->envelope('orcamento.aprovado'));
        $this->projetor->aplicar($this->envelope('evento.desconhecido'));

        $doc = $this->repositorio->buscarPorOsId(self::OS);

        $this->assertSame('APROVADA', $doc['status']);
        $this->assertCount(2, $doc['historico']);
        $this->assertNull($doc['historico'][1]['status']);
    }
}
