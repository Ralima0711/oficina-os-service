<?php

use App\Application\Messaging\Envelope;
use App\Application\Saga\Orchestrator;
use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Tester\Exception\PendingException;
use Behat\Hook\AfterScenario;
use Behat\Hook\AfterSuite;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeSuite;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

/**
 * O Behat anda de mãos dadas com o app, em vez de HTTP falso: o contexto sobe
 * o Laravel de verdade e leva cada cenário pela API, do POST /api/clientes ao
 * outbox da saga. É a mesma disciplina do OrdemServicoApiTest e do
 * SagaOutboxTest — Postgres de verdade, em banco *_test — só que o
 * comportamento vem dito em Gherkin, e não em nome de método.
 *
 * Rodar tudo:
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=oficina_os_test \
 *   DB_USERNAME=postgres DB_PASSWORD=postgres vendor/bin/behat
 *
 * Só o que não usa saga (clientes e veículos):
 *   vendor/bin/behat --tags='~@saga'
 */
final class FeatureContext implements Context
{
    private static ?Application $app = null;

    private ?TestResponse $resposta = null;

    private string $osId = '';

    private string $sagaId = '';

    private string $clienteId = '';

    private string $veiculoId = '';

    // ── Ciclo de vida ──────────────────────────────────────────────

    #[BeforeSuite]
    public static function subirAplicacao(BeforeSuiteScope $scope): void
    {
        self::aplicacao();

        // Mesma guarda do ExigeBancoDeTeste: o migrate:fresh DERRUBA todas as
        // tabelas da conexao ativa. Num banco que nao seja de teste, recusa
        // em vez de apagar — foi assim que o banco de desenvolvimento morreu
        // em 11/11.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $banco = (string) DB::connection()->getDatabaseName();

            if (! str_ends_with($banco, '_test') && ! str_ends_with($banco, '_teste')) {
                throw new DomainException(
                    "RECUSADO: o Behat ia rodar migrate:fresh no banco '{$banco}', que nao "
                    ."termina em '_test' nem '_teste'. Aponte para um banco dedicado: "
                    .'DB_DATABASE=oficina_os_test, por exemplo.'
                );
            }
        }

        self::$app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->call('migrate:fresh', ['--force' => true]);
    }

    #[AfterSuite]
    public static function desligarAplicacao(AfterSuiteScope $scope): void
    {
        self::$app = null;
    }

    #[BeforeScenario]
    public function abrirTransacao(): void
    {
        // Mesmo desenho do RefreshDatabase: uma transacao por cenario, e o
        // rollback desfaz o que o cenario escreveu — inclusive o outbox.
        DB::beginTransaction();
    }

    #[AfterScenario]
    public function desfazerTransacao(): void
    {
        DB::rollBack();
        $this->resposta = null;
        $this->osId = $this->sagaId = $this->clienteId = $this->veiculoId = '';
    }

    #[BeforeScenario('@saga')]
    public function exigirPostgres(BeforeScenarioScope $scope): void
    {
        // Colunas uuid e transacao de verdade: no SQLite o cenario passaria
        // sem validar tipo nenhum — o mesmo porque o ExigeBancoDeTeste
        // recusa SQLite no PHPUnit.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException(
                'Os cenarios @saga exigem Postgres em banco *_test. Rode: '
                .'DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=oficina_os_test '
                .'DB_USERNAME=postgres DB_PASSWORD=postgres vendor/bin/behat — ou, so para o CRUD, '
                ."vendor/bin/behat --tags='~@saga'"
            );
        }
    }

    // ── Dado: cliente, veículo, OS, saga ───────────────────────────

    #[Given('um cliente com um veículo cadastrados')]
    public function dadoUmClienteComUmVeiculoCadastrados(): void
    {
        $this->cadastrarCliente();
        $this->cadastrarVeiculo('DEM1A23');
    }

    #[Given('um cliente cadastrado')]
    public function dadoUmClienteCadastrado(): void
    {
        $this->cadastrarCliente();
    }

    #[Given('uma ordem de serviço aberta para esse veículo')]
    public function dadoUmaOrdemDeServicoAberta(): void
    {
        $this->requisicao('POST', '/api/ordens-servico', [
            'clienteId' => $this->clienteId,
            'veiculoId' => $this->veiculoId,
            'descricaoProblema' => 'Barulho no freio dianteiro',
        ]);

        $this->osId = (string) $this->resposta->json('id');
    }

    #[Given('uma saga iniciada')]
    public function dadoUmaSagaIniciada(): void
    {
        $this->dadoUmClienteComUmVeiculoCadastrados();
        $this->dadoUmaOrdemDeServicoAberta();
        $this->quandoEuConcluoODiagnosticoComItensDeExemplo();
    }

    #[Given('que não existe cliente com o id :id')]
    public function dadoQueNaoExisteClienteComOId(string $id): void
    {
        $this->clienteId = $id;
    }

    #[Given('um veículo com a placa :placa')]
    public function dadoUmVeiculoComAPlaca(string $placa): void
    {
        $this->cadastrarVeiculo($placa);
    }

    // ── Quando: HTTP ───────────────────────────────────────────────

    #[When('eu abro uma ordem de serviço para esse veículo')]
    public function quandoEuAbroUmaOrdemDeServico(): void
    {
        $this->dadoUmaOrdemDeServicoAberta();
    }

    #[When('eu cadastro o cliente :nome com o documento :documento')]
    public function quandoEuCadastroOCliente(string $nome, string $documento): void
    {
        $this->cadastrarCliente($nome, $documento);
    }

    #[When('eu tento cadastrar :nome com o mesmo documento')]
    public function quandoEuTentoCadastrarComOMesmoDocumento(string $nome): void
    {
        $this->cadastrarCliente($nome);
    }

    #[When('eu atualizo o telefone para :telefone')]
    public function quandoEuAtualizoOTelefone(string $telefone): void
    {
        $this->requisicao('PUT', "/api/clientes/{$this->clienteId}", [
            'nome' => 'Johny Oliveira',
            'tipo' => 'pessoa_fisica',
            'documento' => '123.456.789-09',
            'telefone' => $telefone,
            'email' => 'johny@example.com',
        ]);
    }

    #[When('eu cadastro um veículo com a placa :placa')]
    public function quandoEuCadastroUmVeiculoComAPlaca(string $placa): void
    {
        $this->cadastrarVeiculo($placa);
    }

    #[When('eu tento cadastrar um veículo com a placa :placa de novo')]
    public function quandoEuTentoCadastrarUmVeiculoDeNovo(string $placa): void
    {
        $this->cadastrarVeiculo($placa);
    }

    #[When('eu cadastro um veículo para esse cliente com a placa :placa')]
    public function quandoEuCadastroUmVeiculoParaEsseCliente(string $placa): void
    {
        $this->cadastrarVeiculo($placa);
    }

    #[When('eu listo os veículos desse cliente')]
    public function quandoEuListoOsVeiculosDesseCliente(): void
    {
        $this->requisicao('GET', "/api/veiculos?clienteId={$this->clienteId}");
    }

    #[When('eu concluo o diagnóstico com itens de exemplo')]
    public function quandoEuConcluoODiagnosticoComItensDeExemplo(): void
    {
        $this->requisicao('POST', "/api/ordens-servico/{$this->osId}/diagnostico", [
            'mecanicoId' => $this->uuid(),
            'diagnostico' => 'Pastilhas no limite e disco empenado',
            'itensDiagnosticados' => [
                ['descricao' => 'Parafuso', 'tipo' => 'peca', 'quantidade' => 2],
                ['descricao' => 'Alinhamento', 'tipo' => 'servico', 'quantidade' => 1],
            ],
        ]);

        if ($this->resposta->status() === 202) {
            $this->sagaId = (string) $this->resposta->json('sagaId');
        }
    }

    #[When('eu tento concluir o diagnóstico sem itens')]
    public function quandoEuTentoConcluirODiagnosticoSemItens(): void
    {
        $this->requisicao('POST', "/api/ordens-servico/{$this->osId}/diagnostico", [
            'mecanicoId' => $this->uuid(),
            'diagnostico' => 'Nada a reparar, somente revisao',
            'itensDiagnosticados' => [],
        ]);
    }

    #[When('eu tento concluir o diagnóstico de novo')]
    public function quandoEuTentoConcluirODiagnosticoDeNovo(): void
    {
        $this->requisicao('POST', "/api/ordens-servico/{$this->osId}/diagnostico", [
            'mecanicoId' => $this->uuid(),
            'diagnostico' => 'Segunda tentativa de diagnostico',
            'itensDiagnosticados' => [
                ['descricao' => 'Parafuso', 'tipo' => 'peca', 'quantidade' => 1],
            ],
        ]);
    }

    // ── Quando: eventos que os outros serviços mandam ──────────────

    #[When('o orçamento é gerado no valor de :valor')]
    public function quandoOrcamentoGerado(float $valor): void
    {
        $this->saga()->onOrcamentoGerado($this->evento('orcamento.gerado', [
            'osId' => $this->osId,
            'orcamentoId' => $this->id('o-1'),
            'valorTotal' => $valor,
        ]));
    }

    #[When('o orçamento é aprovado')]
    public function quandoOrcamentoAprovado(): void
    {
        $this->saga()->onOrcamentoAprovado($this->evento('orcamento.aprovado', [
            'osId' => $this->osId,
            'orcamentoId' => $this->id('o-1'),
            'decidioEm' => '2026-11-09T10:00:00Z',
        ]));
    }

    #[When('o pagamento é confirmado')]
    public function quandoPagamentoConfirmado(): void
    {
        $this->saga()->onPagamentoConfirmado($this->evento('pagamento.confirmado', [
            'osId' => $this->osId,
            'orcamentoId' => $this->id('o-1'),
            'pagamentoId' => $this->id('p-1'),
            'valorPago' => 241.80,
        ]));
    }

    #[When('a execução é concluída')]
    public function quandoExecucaoConcluida(): void
    {
        $this->saga()->onExecucaoConcluida($this->evento('execucao.concluida', [
            'osId' => $this->osId,
            'execucaoId' => $this->id('e-1'),
        ]));
    }

    #[When('a execução falha por :motivo')]
    public function quandoExecucaoFalha(string $motivo): void
    {
        $this->saga()->onExecucaoFalhou($this->evento('execucao.falhou', [
            'osId' => $this->osId,
            'execucaoId' => $this->id('e-1'),
            'motivo' => $motivo,
        ]));
    }

    #[When('o pagamento é recusado por :motivo')]
    public function quandoPagamentoRecusado(string $motivo): void
    {
        $this->saga()->onPagamentoRecusado($this->evento('pagamento.recusado', [
            'osId' => $this->osId,
            'orcamentoId' => $this->id('o-1'),
            'motivo' => $motivo,
        ]));
    }

    #[When('o orçamento falha por :motivo')]
    public function quandoOrcamentoFalha(string $motivo): void
    {
        $this->saga()->onOrcamentoFalhou($this->evento('orcamento.falhou', [
            'osId' => $this->osId,
            'motivo' => $motivo,
            'ocorridoEm' => '2026-11-09T10:00:00Z',
        ]));
    }

    // ── Então ──────────────────────────────────────────────────────

    #[Then('a resposta deve ter o código :codigo')]
    public function aRespostaDeveTerOCodigo(int $codigo): void
    {
        Assert::assertSame(
            $codigo,
            $this->resposta->status(),
            'corpo da resposta: '.$this->resposta->getContent()
        );
    }

    #[Then('o status da ordem deve ser :status')]
    public function oStatusDaOrdemDeveSer(string $status): void
    {
        // O estado vem do GET, não do corpo do POST: o que se afirma é o que
        // uma consulta devolveria agora.
        $this->requisicao('GET', "/api/ordens-servico/{$this->osId}");

        Assert::assertSame($status, $this->resposta->json('status'));
    }

    #[Then('o documento salvo é :documento')]
    public function oDocumentoSalvoE(string $documento): void
    {
        Assert::assertSame($documento, $this->resposta->json('documento'));
    }

    #[Then('o telefone salvo é :telefone')]
    public function oTelefoneSalvoE(string $telefone): void
    {
        Assert::assertSame($telefone, $this->resposta->json('telefone'));
    }

    #[Then('o veículo pertence ao cliente cadastrado')]
    public function oVeiculoPertenceAoCliente(): void
    {
        Assert::assertSame($this->clienteId, $this->resposta->json('clienteId'));
    }

    #[Then('a lista tem :quantidade veículo')]
    public function aListaTemVeiculos(int $quantidade): void
    {
        Assert::assertCount($quantidade, $this->resposta->json('dados'));
    }

    #[Then('a placa listada é :placa')]
    public function aPlacaListadaE(string $placa): void
    {
        Assert::assertSame($placa, $this->resposta->json('dados.0.placa'));
    }

    #[Then('o outbox deve ter gravado o comando :tipo para :destino')]
    public function oOutboxDeveTerGravadoOComando(string $tipo, string $destino): void
    {
        $outbox = $this->outbox();

        Assert::assertNotEmpty($outbox, 'o outbox saiu vazio');
        Assert::assertSame($tipo, $outbox[0]['tipo']);
        Assert::assertSame($destino, $outbox[0]['destino']);
        Assert::assertNull(
            $outbox[0]['publicado_em'],
            'quem publica é o worker do outbox, não a requisição'
        );
    }

    #[Then('o outbox deve conter na ordem os tipos :tipos')]
    public function oOutboxDeveConterNaOrdem(string $tipos): void
    {
        Assert::assertSame($this->lista($tipos), array_column($this->outbox(), 'tipo'));
    }

    #[Then('as compensações saem na ordem inversa :tipos')]
    public function asCompensacoesSaemNaOrdemInversa(string $tipos): void
    {
        // Mesma leitura do SagaOutboxTest: os 4 primeiros registros sao a ida
        // da saga (GerarOrcamento, prazo, cobranca, execucao); o que vem
        // depois e a compensacao.
        $compensacoes = array_slice(array_column($this->outbox(), 'tipo'), 4);

        Assert::assertSame($this->lista($tipos), $compensacoes);
    }

    #[Then('o outbox deve conter o tipo :tipo')]
    public function oOutboxDeveConterOTipo(string $tipo): void
    {
        Assert::assertContains($tipo, array_column($this->outbox(), 'tipo'));
    }

    #[Then('o outbox não deve conter o tipo :tipo')]
    public function oOutboxNaoDeveConterOTipo(string $tipo): void
    {
        Assert::assertNotContains($tipo, array_column($this->outbox(), 'tipo'));
    }

    #[Then('o outbox deve conter só :primeiro e :segundo')]
    public function oOutboxDeveConterSo(string $primeiro, string $segundo): void
    {
        Assert::assertSame([$primeiro, $segundo], array_column($this->outbox(), 'tipo'));
    }

    // ── Ajudantes ──────────────────────────────────────────────────

    private static function aplicacao(): Application
    {
        if (self::$app !== null) {
            return self::$app;
        }

        // Padroes de teste, ANTES do boot — o mesmo que o phpunit.xml faz, so
        // que aqui o env tem que ficar pronto na unha. O que o operador
        // exportar no shell vence estes padroes: escrever no $_ENV sem
        // conferir apagaria o valor exportado, porque o leitor de env do
        // Laravel olha o $_ENV antes do $_SERVER.
        $padroes = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];

        foreach ($padroes as $nome => $valor) {
            if (getenv($nome) === false) {
                $_ENV[$nome] = $valor;
            }
        }

        self::$app = require __DIR__.'/../../bootstrap/app.php';
        self::$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return self::$app;
    }

    private function requisicao(string $metodo, string $uri, ?array $corpo = null): void
    {
        $app = self::aplicacao();

        $request = Request::create($uri, $metodo, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $corpo === null ? null : json_encode($corpo));

        $this->resposta = new TestResponse($app->make(HttpKernel::class)->handle($request));
    }

    private function saga(): Orchestrator
    {
        return self::aplicacao()->make(Orchestrator::class);
    }

    private function cadastrarCliente(string $nome = 'Johny Oliveira', ?string $documento = null): void
    {
        $this->requisicao('POST', '/api/clientes', [
            'nome' => $nome,
            'tipo' => 'pessoa_fisica',
            'documento' => $documento ?? '123.456.789-09',
            'telefone' => '11987654321',
            'email' => 'johny@example.com',
        ]);

        if ($this->resposta->status() === 201) {
            $this->clienteId = (string) $this->resposta->json('id');
        }
    }

    private function cadastrarVeiculo(string $placa): void
    {
        $this->requisicao('POST', '/api/veiculos', [
            'clienteId' => $this->clienteId,
            'placa' => $placa,
            'marca' => 'Toyota',
            'modelo' => 'Corolla',
            'ano' => 2020,
            'cor' => 'prata',
        ]);

        if ($this->resposta->status() === 201) {
            $this->veiculoId = (string) $this->resposta->json('id');
        }
    }

    private function evento(string $tipo, array $body): Envelope
    {
        return Envelope::create(type: $tipo, body: $body, sagaId: $this->sagaId);
    }

    /** @return array<int, array<string, mixed>> */
    private function outbox(): array
    {
        return DB::table('saga_outbox')
            ->where('os_id', $this->osId)
            ->orderBy('id')
            ->get()
            ->map(fn ($linha) => (array) $linha)
            ->all();
    }

    /** @return array<int, string> */
    private function lista(string $tipos): array
    {
        return array_map(trim(...), explode(',', $tipos));
    }

    /** Id de verdade: as colunas sao uuid, e o Postgres cobra isso. */
    private function uuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * Rotulo legivel -> UUID estavel, como o SagaOutboxTest faz: o
     * orcamentoId do evento de gerado precisa ser o mesmo do de aprovacao.
     */
    private function id(string $rotulo): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, $rotulo)->toString();
    }
}
