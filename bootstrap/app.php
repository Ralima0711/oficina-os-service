<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // routes/api.php nao estava registrado — e nem existia. O servico
        // servia o /up e mais nada.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Span SERVER por requisição: é aqui que o trace nasce quando a chamada
        // entra pela borda. Sem ele o traceparent do Kong não vira span nenhum.
        $middleware->append(\App\Http\Middleware\TraceHttpRequests::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
