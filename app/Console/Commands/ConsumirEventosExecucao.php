<?php

namespace App\Console\Commands;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessageConsumer;
use App\Application\Saga\Orchestrator;
use Illuminate\Console\Command;

class ConsumirEventosExecucao extends Command
{
    protected $signature = 'saga:consumir-execucao';
    protected $description = 'Consome eventos de execução (iniciada, concluida, falhou) e orquestra a saga';

    public function handle(MessageConsumer $consumer, Orchestrator $orchestrator): int
    {
        $this->info('Consumindo eventos de execução...');

        $consumer->consumeEvents(
            queue: 'os.execucao-eventos',
            routingKeys: ['execucao.iniciada', 'execucao.concluida', 'execucao.falhou'],
            handler: function (Envelope $env) use ($orchestrator): void {
                $type = $env->getType();

                match ($type) {
                    'execucao.iniciada'  => $orchestrator->onExecucaoIniciada($env),
                    'execucao.concluida' => $orchestrator->onExecucaoConcluida($env),
                    'execucao.falhou'    => $orchestrator->onExecucaoFalhou($env),
                    default => $this->warn("Tipo de evento desconhecido: {$type}"),
                };
            },
        );

        return Command::SUCCESS;
    }
}
