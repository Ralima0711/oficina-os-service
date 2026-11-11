<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coluna `estado` nao e lida nem escrita por ninguem — e e NOT NULL.
 *
 * A tabela saga_estado nasceu com duas colunas para o mesmo conceito:
 * `estado` (string 32, NOT NULL, sem default) e `status` (string 16, default
 * ATIVA). O Orchestrator usa `status`: grava EM_ANDAMENTO na abertura e muda
 * dali em diante. `estado` ficou sem um unico uso em app/ ou tests/.
 *
 * Como ela e NOT NULL e nao tem default, o insert de abertura da saga morria em
 *
 *   SQLSTATE[23502]: null value in column "estado" violates not-null constraint
 *
 * ou seja, nenhuma saga chegava a ser aberta. Dropar e melhor do que deixar
 * nullable: coluna que ninguem le e armadilha para quem vier depois — a mesma
 * historia do JWT_PUBLIC_KEY nos manifestos e das chaves FILA_* no ConfigMap.
 *
 * O down recria como nullable de proposito. Recriar NOT NULL sem default
 * falharia em qualquer base que ja tenha linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saga_estado', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }

    public function down(): void
    {
        Schema::table('saga_estado', function (Blueprint $table) {
            $table->string('estado', 32)->nullable()->after('passo_atual');
        });
    }
};
