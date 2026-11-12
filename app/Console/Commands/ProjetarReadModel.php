<?php

namespace App\Console\Commands;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessageConsumer;
use App\Application\ReadModel\ProjetorDeOrdens;
use Illuminate\Console\Command;

/**
 * Worker do read model (CQRS, ADR-0009).
 *
 * Consome os eventos da saga e projeta status + histórico no MongoDB.
 * Roda separado do worker da saga: projeção atrasada não pode travar a escrita.
 */
class ProjetarReadModel extends Command
{
    protected $signature = 'readmodel:projetar';
    protected $description = 'Projeta os eventos da saga no read model de status e histórico (MongoDB)';

    public function handle(MessageConsumer $consumer, ProjetorDeOrdens $projetor): int
    {
        $this->info('Projetando eventos no read model...');

        $consumer->consumeEvents(
            queue: 'os.readmodel-eventos',
            routingKeys: array_keys(ProjetorDeOrdens::STATUS_POR_EVENTO),
            handler: fn (Envelope $env) => $projetor->aplicar($env),
        );

        return Command::SUCCESS;
    }
}
