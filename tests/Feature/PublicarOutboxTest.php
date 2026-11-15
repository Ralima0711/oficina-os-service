<?php

namespace Tests\Feature;

use App\Application\Saga\OutboxPublisher;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * O worker saga:publicar-outbox: o laco em volta do OutboxPublisher.
 *
 * Mesmo motivo do ConsumirEventosSagaTest: a logica do publicador tem teste
 * proprio, mas o comando que o Deployment roda nao tinha nenhum. O que se
 * trava aqui e o que o operador ve e o que o Kubernetes espera:
 *
 *   - quantas sairam por passada;
 *   - o alerta de mensagem travada no teto de tentativas, que nao aparece em
 *     lugar nenhum alem deste log;
 *   - opcoes saneadas (limite minimo 1);
 *   - SIGTERM termina a passada atual e sai com sucesso.
 */
class PublicarOutboxTest extends TestCase
{
    private MockInterface $publicador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicador = Mockery::mock(OutboxPublisher::class);
        $this->app->instance(OutboxPublisher::class, $this->publicador);
    }

    protected function tearDown(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }

        parent::tearDown();
    }

    private function travada(string $tipo, string $os, string $erro): object
    {
        return (object) ['tipo' => $tipo, 'os_id' => $os, 'ultimo_erro' => $erro];
    }

    public function test_uma_passada_informa_quantas_sairam_e_encerra(): void
    {
        $this->publicador->shouldReceive('publicarPendentes')->once()->with(50)->andReturn(3);
        $this->publicador->shouldReceive('travadas')->once()->andReturn(new Collection());

        $this->artisan('saga:publicar-outbox', ['--uma-vez' => true])
            ->expectsOutput('publicadas: 3')
            ->doesntExpectOutputToContain('ATENCAO')
            ->expectsOutput('outbox: encerrado')
            ->assertSuccessful();
    }

    public function test_passada_vazia_nao_anuncia_publicacao(): void
    {
        $this->publicador->shouldReceive('publicarPendentes')->once()->andReturn(0);
        $this->publicador->shouldReceive('travadas')->once()->andReturn(new Collection());

        $this->artisan('saga:publicar-outbox', ['--uma-vez' => true])
            ->doesntExpectOutputToContain('publicadas')
            ->expectsOutput('outbox: encerrado')
            ->assertSuccessful();
    }

    public function test_repassa_o_limite_e_nunca_menos_que_um(): void
    {
        $this->publicador->shouldReceive('publicarPendentes')->once()->with(7)->andReturn(0);
        $this->publicador->shouldReceive('travadas')->andReturn(new Collection());

        $this->artisan('saga:publicar-outbox', ['--uma-vez' => true, '--limite' => 7])->assertSuccessful();

        $this->publicador->shouldReceive('publicarPendentes')->once()->with(1)->andReturn(0);

        $this->artisan('saga:publicar-outbox', ['--uma-vez' => true, '--limite' => 0])->assertSuccessful();
    }

    public function test_alerta_as_mensagens_travadas_mostrando_no_maximo_cinco(): void
    {
        $travadas = new Collection(array_map(
            fn (int $i) => $this->travada('GerarOrcamento', "os-{$i}", "erro {$i}"),
            range(1, 6),
        ));

        $this->publicador->shouldReceive('publicarPendentes')->once()->andReturn(0);
        $this->publicador->shouldReceive('travadas')->once()->andReturn($travadas);

        $this->artisan('saga:publicar-outbox', ['--uma-vez' => true])
            ->expectsOutput('ATENCAO: 6 mensagem(ns) travada(s) no outbox apos o limite de tentativas')
            ->expectsOutput('  GerarOrcamento os=os-1 erro=erro 1')
            ->expectsOutput('  GerarOrcamento os=os-5 erro=erro 5')
            ->doesntExpectOutput('  GerarOrcamento os=os-6 erro=erro 6')
            ->assertSuccessful();
    }

    /**
     * Sem --uma-vez o worker fica em laco: dorme quando a passada veio vazia e
     * so sai no SIGTERM — terminando a passada em curso, com codigo de sucesso,
     * que e o que o Kubernetes espera de um encerramento pedido.
     */
    public function test_em_laco_dorme_quando_vazio_e_sai_limpo_no_sigterm(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('precisa de pcntl e posix para mandar o sinal ao proprio processo');
        }

        $passadas = 0;

        $this->publicador->shouldReceive('publicarPendentes')->twice()->andReturnUsing(function () use (&$passadas): int {
            $passadas++;

            if ($passadas === 2) {
                // O sinal chega no meio da segunda passada.
                posix_kill(getmypid(), SIGTERM);
                pcntl_signal_dispatch();

                return 4;
            }

            return 0;
        });
        $this->publicador->shouldReceive('travadas')->twice()->andReturn(new Collection());

        $inicio = microtime(true);

        $this->artisan('saga:publicar-outbox', ['--intervalo' => 1])
            ->expectsOutput('sinal recebido, encerrando apos a passada atual')
            ->expectsOutput('publicadas: 4')
            ->expectsOutput('outbox: encerrado')
            ->assertSuccessful();

        $this->assertSame(2, $passadas);
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $inicio, 'a passada vazia devia ter dormido o intervalo');
    }
}
