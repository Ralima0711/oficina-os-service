<?php

use App\Http\Controllers\OrdemServicoController;
use Illuminate\Support\Facades\Route;

/*
 * API do OS Service.
 *
 * Ate 11/11 este arquivo nao existia: o servico subia, servia o /up e mais
 * nada. O Orchestrator diz, no docblock do iniciarSaga, que ele "e o unico
 * ponto de entrada que nao vem de um evento — vem da API, quando o mecanico
 * fecha o diagnostico". A API nunca tinha sido escrita, entao a saga so
 * disparava por tinker.
 *
 * Sao tres rotas, e so as tres que a saga precisa:
 *
 *   POST /api/ordens-servico                   abre a OS (RECEBIDA)
 *   POST /api/ordens-servico/{id}/diagnostico  fecha o diagnostico e INICIA A SAGA
 *   GET  /api/ordens-servico/{id}              estado atual, para acompanhar
 *
 * Os prefixos /api/clientes e /api/veiculos, marcados A CONFIRMAR no Kong,
 * continuam fora: cliente e veiculo sao da API da Fase 3, e este servico so
 * guarda os ids.
 */

Route::post('ordens-servico', [OrdemServicoController::class, 'store'])
    ->name('ordens-servico.store');

Route::post('ordens-servico/{id}/diagnostico', [OrdemServicoController::class, 'concluirDiagnostico'])
    ->name('ordens-servico.diagnostico');

Route::get('ordens-servico/{id}', [OrdemServicoController::class, 'show'])
    ->name('ordens-servico.show');
