<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saga_estado', function (Blueprint $table) {
            $table->uuid('saga_id')->primary();
            $table->uuid('os_id');
            $table->unsignedTinyInteger('passo_atual');
            $table->string('estado', 32);
            $table->uuid('orcamento_id')->nullable();
            $table->uuid('pagamento_id')->nullable();
            $table->uuid('execucao_id')->nullable();
            $table->string('status', 16)->default('ATIVA');
            $table->string('motivo_cancelamento')->nullable();
            $table->timestamp('criada_em')->useCurrent();
            $table->timestamp('atualizada_em')->nullable()->useCurrentOnUpdate();
            $table->timestamp('finalizada_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saga_estado');
    }
};
