<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensagens_processadas', function (Blueprint $table) {
            $table->uuid('message_id')->primary();
            $table->uuid('saga_id')->nullable();
            $table->string('tipo', 64);
            $table->timestamp('processado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens_processadas');
    }
};
