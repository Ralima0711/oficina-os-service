<?php

namespace Tests\Feature;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessageConsumer;
use App\Application\ReadModel\ProjetorDeOrdens;
use App\Application\ReadModel\RepositorioDeLeituraInterface;
use Closure;
use Tests\Support\RepositorioDeLeituraEmMemoria;
use Tests\TestCase;

/**
 * O worker readmodel:projetar, ligado ao consumidor e ao projetor.
 *
 * Mesma lacuna que o ConsumirEventosSagaTest fechou para o saga:consumir: o
 * projetor tem teste de unidade, mas o comando que o Deployment roda nao tinha
 * nenhum — e e no comando que fica a fiacao (fila, routing keys, ordem entre
 * criar indice e consumir). Nada aqui toca o broker nem o MongoDB.
 */
class ProjetarReadModelTest extends TestCase
{
    private ?Closure $handler = null;
    private string $fila = '';
    private array $routingKeys = [];
    private RepositorioDeLeituraEmMemoria $leitura;

    /** Ordem em que indice e consumo aconteceram. */
    public array $passos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $teste = $this;

        $this->leitura = new class ($teste) extends RepositorioDeLeituraEmMemoria {
            public function __construct(private ProjetarReadModelTest $teste) {}

            public function garantirIndices(): void
            {
                $this->teste->passos[] = 'indices';
            }
        };

        $this->app->instance(RepositorioDeLeituraInterface::class, $this->leitura);
        $this->app->forgetInstance(ProjetorDeOrdens::class);

        $this->app->bind(MessageConsumer::class, fn () => new class ($teste) extends MessageConsumer {
            public function __construct(private ProjetarReadModelTest $teste)
            {
                parent::__construct('nao-usado', 5672, 'guest', 'guest');
            }

            public function consumeEvents(string $queue, array $routingKeys, Closure $handler): void
            {
                $this->teste->capturar($queue, $routingKeys, $handler);
            }
        });
    }

    public function capturar(string $queue, array $routingKeys, Closure $handler): void
    {
        $this->passos[] = 'consumir';
        $this->fila = $queue;
        $this->routingKeys = $routingKeys;
        $this->handler = $handler;
    }

    public function test_garante_o_indice_antes_de_comecar_a_consumir(): void
    {
        $this->artisan('readmodel:projetar')
            ->expectsOutput('Projetando eventos no read model...')
            ->assertSuccessful();

        // Sem o indice unico em osId, duas replicas inserem dois documentos
        // para a mesma OS: o indice tem que vir ANTES da primeira mensagem.
        $this->assertSame(['indices', 'consumir'], $this->passos);
    }

    public function test_consome_da_fila_do_read_model_os_eventos_que_o_projetor_conhece(): void
    {
        $this->artisan('readmodel:projetar')->assertSuccessful();

        $this->assertSame('os.readmodel.eventos', $this->fila);
        $this->assertSame(array_keys(ProjetorDeOrdens::STATUS_POR_EVENTO), $this->routingKeys);
    }

    public function test_o_handler_registrado_projeta_o_evento_no_read_model(): void
    {
        $this->artisan('readmodel:projetar')->assertSuccessful();

        $osId = '11111111-1111-4111-8111-111111111111';
        $env = Envelope::create('orcamento.aprovado', ['osId' => $osId], '22222222-2222-4222-8222-222222222222');

        ($this->handler)($env);

        $doc = $this->leitura->buscarPorOsId($osId);
        $this->assertNotNull($doc, 'o handler do comando devia ter projetado o evento');
        $this->assertSame('APROVADA', $doc['status']);
        $this->assertSame([$env->getMessageId()], $doc['messageIds']);
    }
}
