<?php

namespace App\Console\Commands;

use App\Application\Saga\OutboxPublisher;
use Illuminate\Console\Command;

/**
 * Worker do outbox: publica no RabbitMQ o que o orquestrador gravou.
 *
 *   php artisan saga:publicar-outbox          roda em laço, até receber sinal
 *   php artisan saga:publicar-outbox --uma-vez  uma passada e sai (útil em teste)
 *
 * Roda num Deployment próprio, separado do consumidor e da API. São três
 * processos com responsabilidades e modos de falha diferentes: este só escreve
 * no broker, o consumidor só lê, e a API não faz nem um nem outro.
 */
class PublicarOutbox extends Command
{
    protected $signature = 'saga:publicar-outbox
                            {--uma-vez : Faz uma passada e encerra}
                            {--intervalo=1 : Segundos entre as passadas}
                            {--limite=50 : Mensagens por passada}';

    protected $description = 'Publica as mensagens pendentes do outbox da saga';

    private bool $parar = false;

    public function handle(OutboxPublisher $publisher): int
    {
        $this->registrarSinais();

        $umaVez = (bool) $this->option('uma-vez');
        $intervalo = max(1, (int) $this->option('intervalo'));
        $limite = max(1, (int) $this->option('limite'));

        do {
            $publicadas = $publisher->publicarPendentes($limite);

            if ($publicadas > 0) {
                $this->info("publicadas: {$publicadas}");
            }

            $travadas = $publisher->travadas();

            if ($travadas->isNotEmpty()) {
                // Saga parada com mensagem presa no outbox não aparece em lugar
                // nenhum além daqui. É o que um alerta deve observar.
                $this->error(
                    "ATENCAO: {$travadas->count()} mensagem(ns) travada(s) no outbox apos o limite de tentativas"
                );
                foreach ($travadas->take(5) as $linha) {
                    $this->line("  {$linha->tipo} os={$linha->os_id} erro={$linha->ultimo_erro}");
                }
            }

            if ($umaVez || $this->parar) {
                break;
            }

            // Dorme só quando não havia nada: com fila cheia, não faz sentido esperar.
            if ($publicadas === 0) {
                sleep($intervalo);
            }
        } while (!$this->parar);

        $this->info('outbox: encerrado');

        return self::SUCCESS;
    }

    /** SIGTERM do Kubernetes termina a passada atual em vez de cortar no meio. */
    private function registrarSinais(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $sinal) {
            pcntl_signal($sinal, function () {
                $this->parar = true;
                $this->line('sinal recebido, encerrando apos a passada atual');
            });
        }
    }
}
