<?php

namespace App\Console\Commands;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessageConsumer;
use App\Application\Saga\Orchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Worker do orquestrador: consome os eventos de domínio e avança a saga.
 *
 *   php artisan saga:consumir
 *
 * Lê a fila os.saga.eventos, ligada ao exchange oficina.eventos pelas routing
 * keys orcamento.*, pagamento.*, execucao.* e aprovacao.expirada.
 *
 * Substitui o saga:consumir-execucao, que cobria só o passo 4.
 *
 * Este processo NÃO publica nada. Toda saída da saga vai para o outbox, dentro
 * da transação, e sai pelo saga:publicar-outbox. Ver Outbox.
 */
class ConsumirEventosSaga extends Command
{
    protected $signature = 'saga:consumir {--fila=os.saga.eventos}';

    protected $description = 'Consome os eventos de domínio e avança a saga';

    /**
     * Routing key → método do orquestrador.
     *
     * O que não está aqui é descartado com log: evento novo no exchange não
     * pode derrubar o worker nem ir para a DLQ sem motivo.
     */
    /**
     * Routing keys com que os.saga.eventos esta ligada ao exchange no
     * definitions.json do oficina-infra-fase4. Aqui elas sao so para log: o
     * bind e do infra, nao deste processo.
     */
    private const ROUTING_KEYS = ['orcamento.*', 'pagamento.*', 'execucao.*', 'aprovacao.*'];

    private const ROTEAMENTO = [
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

    public function handle(MessageConsumer $consumer, Orchestrator $orchestrator): int
    {
        $fila = (string) $this->option('fila');

        $this->info("saga: consumindo {$fila}");

        // consumeEvents, nao consume: o consume() e privado, implementacao
        // compartilhada entre consumeEvents e consumeCommands. E o handler
        // recebe um Envelope ja montado — o MessageConsumer decodifica o JSON,
        // confere o header.messageId e resolve o traceparent antes de chamar.
        $consumer->consumeEvents(
            queue: $fila,
            routingKeys: self::ROUTING_KEYS,
            handler: function (Envelope $envelope) use ($orchestrator): void {
                $tipo = $envelope->getType();

                $metodo = self::ROTEAMENTO[$tipo] ?? null;

                if ($metodo === null) {
                    // Confirma a mensagem: um evento que este servico nao trata
                    // nao e erro dele, e deixa-lo voltando para a fila trava o
                    // consumo.
                    Log::info('Saga: evento sem handler, confirmado sem efeito', [
                        'type' => $tipo,
                        'messageId' => $envelope->getMessageId(),
                    ]);

                    return;
                }

                $orchestrator->{$metodo}($envelope);
            },
        );

        return self::SUCCESS;
    }
}
