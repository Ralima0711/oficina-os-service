<?php

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

return [

    'host' => env('RABBITMQ_HOST', 'localhost'),
    'port' => env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    'exchange' => env('RABBITMQ_EXCHANGE', 'oficina.eventos'),

    'queues' => [
        'execucao.enviar-para-execucao' => [
            'type' => 'command',
        ],
        'execucao.cancelar-execucao' => [
            'type' => 'command',
        ],
        'billing.gerar-orcamento' => [
            'type' => 'command',
        ],
        'billing.cobrar-pagamento' => [
            'type' => 'command',
        ],
        'billing.anular-orcamento' => [
            'type' => 'command',
        ],
        'billing.estornar-pagamento' => [
            'type' => 'command',
        ],
    ],

    'event_bindings' => [
        'execucao.iniciada'   => 'os.execucao-eventos',
        'execucao.concluida'  => 'os.execucao-eventos',
        'execucao.falhou'     => 'os.execucao-eventos',
        'orcamento.gerado'    => 'os.orcamento-eventos',
        'orcamento.falhou'    => 'os.orcamento-eventos',
        'orcamento.aprovado'  => 'os.orcamento-eventos',
        'orcamento.recusado'  => 'os.orcamento-eventos',
        'aprovacao.expirada'  => 'os.orcamento-eventos',
        'pagamento.confirmado' => 'os.pagamento-eventos',
        'pagamento.recusado'  => 'os.pagamento-eventos',
    ],

];
