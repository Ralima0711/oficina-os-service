<?php

namespace Tests\Feature;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessageConsumer;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Trava a compatibilidade do MessageConsumer com o php-amqplib INSTALADO.
 *
 * Este servico esta no `php-amqplib ^2.8`; o billing e a execucao estao no
 * `^3.7`. Essa divergencia ja derrubou o worker tres vezes em producao local,
 * sempre do mesmo jeito: um metodo que so existe no 3.x, chamado num projeto
 * que resolve o 2.8.
 *
 *   1. $channel->is_consuming()   -> Call to undefined method, no laco
 *   2. $msg->getDeliveryTag()     -> Call to undefined method, na 1a mensagem
 *
 * Nenhum teste pegava isso porque todos os outros ou dublavam o consumidor
 * inteiro ou nao chegavam a tocar um AMQPMessage de verdade. Aqui o
 * AMQPMessage e real, vindo da biblioteca instalada: se alguem voltar a usar
 * API de outro major, ou se a versao resolvida mudar, isto falha na hora.
 *
 * Nada conecta no broker: o canal e dublado e tratar() e chamado direto.
 */
class MessageConsumerCompatibilidadeTest extends TestCase
{
    private const TAG = 42;

    private function consumidor(): MessageConsumer
    {
        return new MessageConsumer('nao-usado', 5672, 'guest', 'guest');
    }

    /** Mensagem real da biblioteca instalada, montada como o broker entrega. */
    private function mensagem(Envelope $env, ?string $traceparentNoHeader = null): AMQPMessage
    {
        $propriedades = ['content_type' => 'application/json'];

        if ($traceparentNoHeader !== null) {
            $propriedades['application_headers'] = new AMQPTable([
                'traceparent' => $traceparentNoHeader,
            ]);
        }

        $msg = new AMQPMessage($env->toJson(), $propriedades);

        // E assim que o AMQPChannel do 2.x entrega: delivery_info preenchido.
        $msg->delivery_info['delivery_tag'] = self::TAG;

        return $msg;
    }

    private function tratar(AMQPMessage $msg, AMQPChannel $canal, ?\Closure $handler = null): void
    {
        $metodo = new ReflectionMethod(MessageConsumer::class, 'tratar');
        $metodo->setAccessible(true);
        $metodo->invoke($this->consumidor(), $msg, $handler ?? fn () => null, $canal);
    }

    // ── O que teria pego o getDeliveryTag() ───────────────────────

    public function test_confirma_a_mensagem_com_o_delivery_tag_da_biblioteca_instalada(): void
    {
        $canal = Mockery::mock(AMQPChannel::class);
        $canal->shouldReceive('basic_ack')->once()->with(self::TAG);
        $canal->shouldNotReceive('basic_nack');

        $this->tratar(
            $this->mensagem(Envelope::create('orcamento.gerado', ['osId' => 'x'], 'saga-1')),
            $canal
        );

        $this->assertTrue(true, 'tratar() percorreu o caminho de ACK sem erro de API');
    }

    public function test_mensagem_ilegivel_vai_para_a_dlq_com_o_mesmo_tag(): void
    {
        $canal = Mockery::mock(AMQPChannel::class);
        // sem header.messageId: ilegivel, nack sem requeue
        $canal->shouldReceive('basic_nack')->once()->with(self::TAG, false, false);
        $canal->shouldNotReceive('basic_ack');

        $msg = new AMQPMessage('{"isso":"nao e um envelope"}');
        $msg->delivery_info['delivery_tag'] = self::TAG;

        $this->tratar($msg, $canal);
    }

    public function test_json_quebrado_vai_para_a_dlq(): void
    {
        $canal = Mockery::mock(AMQPChannel::class);
        $canal->shouldReceive('basic_nack')->once()->with(self::TAG, false, false);

        $msg = new AMQPMessage('{isso nao e json');
        $msg->delivery_info['delivery_tag'] = self::TAG;

        $this->tratar($msg, $canal);
    }

    // ── O que teria pego o get_properties() ───────────────────────

    /**
     * O traceparent do header AMQP vence o do envelope quando os dois existem
     * (regra do contrato). Ler o header usa get_properties(), que no 2.8 mora
     * no GenericContent, a classe pai — este teste prova que a leitura
     * funciona contra a biblioteca instalada.
     */
    public function test_o_traceparent_do_header_amqp_vence_o_do_envelope(): void
    {
        $doHeader = '00-11111111111111111111111111111111-2222222222222222-01';

        $env = Envelope::create(
            type: 'orcamento.gerado',
            body: ['osId' => 'x'],
            sagaId: 'saga-1',
            traceparent: '00-99999999999999999999999999999999-8888888888888888-01',
        );

        $canal = Mockery::mock(AMQPChannel::class);
        $canal->shouldReceive('basic_ack')->once()->with(self::TAG);

        $recebido = null;

        $this->tratar(
            $this->mensagem($env, traceparentNoHeader: $doHeader),
            $canal,
            function (Envelope $e) use (&$recebido): void {
                $recebido = $e->getTraceparent();
            }
        );

        $this->assertSame($doHeader, $recebido);
    }

    public function test_sem_header_amqp_vale_o_traceparent_do_envelope(): void
    {
        $doEnvelope = '00-99999999999999999999999999999999-8888888888888888-01';

        $env = Envelope::create(
            type: 'orcamento.gerado',
            body: ['osId' => 'x'],
            sagaId: 'saga-1',
            traceparent: $doEnvelope,
        );

        $canal = Mockery::mock(AMQPChannel::class);
        $canal->shouldReceive('basic_ack')->once()->with(self::TAG);

        $recebido = null;

        $this->tratar($this->mensagem($env), $canal, function (Envelope $e) use (&$recebido): void {
            $recebido = $e->getTraceparent();
        });

        $this->assertSame($doEnvelope, $recebido);
    }
}
