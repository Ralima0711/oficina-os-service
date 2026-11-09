<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox transacional do orquestrador.
 *
 * O problema que esta tabela resolve: mudar o estado da OS e publicar o comando
 * seguinte são duas coisas que precisam acontecer juntas ou nenhuma. Publicar
 * depois do commit abre uma janela — se o processo morrer ali, a OS fica no
 * estado novo e o comando nunca sai. A saga para no meio sem log de erro, que é
 * o pior modo de falha desta arquitetura.
 *
 * Com o outbox, a intenção de publicar é gravada DENTRO da mesma transação que
 * aplica a transição. Ou as duas entram, ou nenhuma. Um processo separado lê as
 * linhas pendentes e publica.
 *
 * O custo: a publicação deixa de ser imediata e passa a ser "quase imediata", e
 * a entrega vira at-least-once — a mesma mensagem pode sair duas vezes se o
 * processo morrer entre publicar e marcar. Os dois são aceitáveis porque todo
 * consumidor é idempotente pelo messageId (contrato v1.1, Parte 5, regra 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saga_outbox', function (Blueprint $table) {
            $table->id();

            // O mesmo messageId que vai no envelope. Único: a garantia de que
            // a mesma intenção não é gravada duas vezes, mesmo sob retry.
            $table->uuid('message_id')->unique();
            $table->uuid('saga_id')->index();
            $table->uuid('os_id')->index();

            // 'comando' vai para uma fila direta; 'evento' vai para o exchange.
            $table->string('canal', 16);

            // Fila, quando comando. Routing key, quando evento.
            $table->string('destino');

            $table->string('tipo');
            $table->jsonb('payload');

            $table->timestamp('criado_em')->useCurrent();
            $table->timestamp('publicado_em')->nullable();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->text('ultimo_erro')->nullable();

            // O índice que o publicador usa: pendentes, na ordem de criação.
            // Parcial, porque linha publicada não interessa mais à varredura e
            // a tabela só cresce.
            $table->index(['criado_em'], 'saga_outbox_pendentes')
                ->where('publicado_em is null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saga_outbox');
    }
};
