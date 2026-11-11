<?php

namespace Tests\Feature;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessageConsumer;
use App\Application\Saga\Orchestrator;
use Closure;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * O teste que faltava: liga o comando ao consumidor e ao orquestrador.
 *
 * As 244 testes do servico cobrem o dominio e o outbox, mas nenhuma chamava o
 * handle() do saga:consumir. Por isso dois defeitos que impedem o worker de
 * subir atravessaram tudo e so apareceram quando o container rodou de verdade,
 * em 10/11:
 *
 *   1. o comando chamava consume(), que e private — Error na primeira linha, e
 *      o pod em CrashLoopBackOff sem nunca ter consumido nada;
 *   2. o handler declarava `array $mensagem` e chamava Envelope::fromArray(),
 *      mas o MessageConsumer entrega um Envelope ja montado — TypeError na
 *      primeira mensagem que chegasse, depois de o pod parecer saudavel.
 *
 * Os dois sao de fiacao, nao de logica, e e exatamente o que teste de unidade
 * de dominio nao pega. Nada aqui toca o broker.
 */
class ConsumirEventosSagaTest extends TestCase
{
    private ?Closure $handler = null;
    private string $fila = '';
    private array $routingKeys = [];
    private MockInterface $saga;

    protected function setUp(): void
    {
        parent::setUp();

        // Duble do consumidor: guarda o handler em vez de abrir conexao.
        $this->app->bind(MessageConsumer::class, fn () => new class ($this) extends MessageConsumer {
            public function __construct(private ConsumirEventosSagaTest $teste)
            {
                parent::__construct('nao-usado', 5672, 'guest', 'guest');
            }

            public function consumeEvents(string $queue, array $routingKeys, Closure $handler): void
            {
                $this->teste->capturar($queue, $routingKeys, $handler);
            }
        });

        $this->saga = Mockery::mock(Orchestrator::class)->shouldIgnoreMissing();
        $this->app->instance(Orchestrator::class, $this->saga);
    }

    public function capturar(string $queue, array $routingKeys, Closure $handler): void
    {
        $this->fila = $queue;
        $this->routingKeys = $routingKeys;
        $this->handler = $handler;
    }

    private function rodar(): void
    {
        $this->artisan('saga:consumir')->assertSuccessful();
    }

    // ── O que teria pego o CrashLoopBackOff ────────────────────────

    public function test_o_comando_chega_ao_consumidor_sem_estourar(): void
    {
        $this->rodar();

        $this->assertNotNull(
            $this->handler,
            'saga:consumir nao chegou a registrar handler no consumidor'
        );
    }

    public function test_consome_a_fila_do_contrato(): void
    {
        $this->rodar();

        $this->assertSame('os.saga.eventos', $this->fila);
    }

    public function test_as_routing_keys_sao_as_do_definitions_json(): void
    {
        $this->rodar();

        $this->assertSame(
            ['orcamento.*', 'pagamento.*', 'execucao.*', 'aprovacao.*'],
            $this->routingKeys
        );
    }

    // ── O que teria pego o TypeError ──────────────────────────────

    /**
     * O MessageConsumer entrega Envelope, nao array. Se o handler voltar a
     * declarar array, este teste estoura TypeError aqui — que e o ponto.
     */
    public function test_o_handler_recebe_um_envelope_e_roteia(): void
    {
        $this->rodar();

        $env = Envelope::create(
            type: 'orcamento.gerado',
            body: ['orcamentoId' => 'orc-1'],
            sagaId: 'os-1',
        );

        ($this->handler)($env);

        $this->saga->shouldHaveReceived('onOrcamentoGerado')->once()->with($env);
    }

    /**
     * Um a um, para que um roteamento apagado por engano apareca nomeado.
     */
    public function test_cada_evento_do_contrato_cai_no_seu_metodo(): void
    {
        $esperado = [
            'orcamento.gerado' => 'onOrcamentoGerado',
            'orcamento.falhou' => 'onOrcamentoFalhou',
            'orcamento.aprovado' => 'onOrcamentoAprovado',
            'orcamento.recusado' => 'onOrcamentoRecusado',
            'aprovacao.expirada' => 'onAprovacaoExpirada',
            'pagamento.confirmado' => 'onPagamentoConfirmado',
            'pagamento.recusado' => 'onPagamentoRecusado',
            'execucao.iniciada' => 'onExecucaoIniciada',
            'execucao.concluida' => 'onExecucaoConcluida',
            'execucao.falhou' => 'onExecucaoFalhou',
        ];

        $this->rodar();

        foreach ($esperado as $tipo => $metodo) {
            ($this->handler)(Envelope::create($tipo, [], 'os-1'));
            $this->saga->shouldHaveReceived($metodo)->once();
        }
    }

    /**
     * Evento novo no exchange nao pode derrubar o worker nem ir para a DLQ:
     * o handler confirma sem efeito.
     */
    public function test_evento_sem_handler_nao_estoura(): void
    {
        $this->rodar();

        ($this->handler)(Envelope::create('os.inventada', [], 'os-1'));

        $this->saga->shouldNotHaveReceived('onOrcamentoGerado');
    }
}
