<?php

use App\Http\Controllers\ClienteController;
use App\Http\Controllers\OrdemServicoController;
use App\Http\Controllers\VeiculoController;
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
 * /api/clientes e /api/veiculos tambem sao deste servico, e nao da API da Fase
 * 3: o ADR-0007 decidiu em 20/10 que "cliente e veiculo ficam no OS Service,
 * nao em um quarto servico". A decisao estava registrada e o recorte parou no
 * scaffolding — entidades e value objects existiam, sem tabela, sem
 * repositorio, sem rota e sem teste.
 */

Route::post('ordens-servico', [OrdemServicoController::class, 'store'])
    ->name('ordens-servico.store');

Route::post('ordens-servico/{id}/diagnostico', [OrdemServicoController::class, 'concluirDiagnostico'])
    ->name('ordens-servico.diagnostico');

Route::get('ordens-servico/{id}', [OrdemServicoController::class, 'show'])
    ->name('ordens-servico.show');

Route::get('ordens-servico/{id}/historico', [OrdemServicoController::class, 'historico'])
    ->name('ordens-servico.historico');

// ----------------------------------------------------------------- Cliente
Route::get('clientes', [ClienteController::class, 'index'])->name('clientes.index');
Route::post('clientes', [ClienteController::class, 'store'])->name('clientes.store');
Route::get('clientes/{id}', [ClienteController::class, 'show'])->name('clientes.show');
Route::put('clientes/{id}', [ClienteController::class, 'update'])->name('clientes.update');

// ----------------------------------------------------------------- Veiculo
// O index aceita ?clienteId= para listar so os veiculos de um cliente.
Route::get('veiculos', [VeiculoController::class, 'index'])->name('veiculos.index');
Route::post('veiculos', [VeiculoController::class, 'store'])->name('veiculos.store');
Route::get('veiculos/{id}', [VeiculoController::class, 'show'])->name('veiculos.show');
