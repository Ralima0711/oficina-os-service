<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordens_servico', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cliente_id');
            $table->uuid('veiculo_id');
            $table->uuid('mecanico_id')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('version')->default(1);
            $table->text('descricao_problema');
            $table->text('diagnostico')->nullable();
            $table->decimal('valor_total', 12, 2)->nullable();
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('criada_em')->useCurrent();
            $table->timestamp('atualizada_em')->nullable()->useCurrentOnUpdate();
            $table->timestamp('concluida_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordens_servico');
    }
};
