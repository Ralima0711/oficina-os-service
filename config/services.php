<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Servicos da Fase 4
    |--------------------------------------------------------------------------
    |
    | Enderecos dos outros dois microsservicos, usados APENAS pela consulta
    | agregada (API Composition). Nenhuma outra parte do OS Service fala com
    | eles por HTTP: a saga inteira e por mensageria.
    |
    */

    'billing' => [
        'url' => env('BILLING_URL', 'http://billing-service'),
    ],

    'execucao' => [
        'url' => env('EXECUCAO_URL', 'http://execucao-service'),
    ],

    'composicao' => [
        // Timeout curto de proposito: a composicao e leitura de conveniencia.
        // Se um participante demora, a resposta sai sem a parte dele e diz
        // isso, em vez de a consulta inteira ficar pendurada no mais lento.
        'timeout' => (float) env('COMPOSICAO_TIMEOUT', 2.0),

        // Circuit Breaker (Aula 2). Tres falhas dentro de um minuto abrem o
        // circuito por trinta segundos. Abrir cedo demais corta um
        // participante que teve um solucao isolado; tarde demais e nao ter
        // disjuntor.
        'falhas_para_abrir' => (int) env('COMPOSICAO_FALHAS_PARA_ABRIR', 3),
        'janela_de_falhas' => (int) env('COMPOSICAO_JANELA_DE_FALHAS', 60),
        'segundos_aberto' => (int) env('COMPOSICAO_SEGUNDOS_ABERTO', 30),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
