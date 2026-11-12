<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os tres ids de correlacao faltavam em ordens_servico.
 *
 * A entidade OrdemServico carrega orcamentoId, pagamentoId e execucaoId, e o
 * EloquentOrdemServicoRepository grava e le os tres em `ordens_servico` — no
 * insert, no update com lock otimista e na hidratacao. A migration original da
 * tabela nao criou nenhum deles: eles existem so em `saga_estado`, que e o
 * registro proprio da saga e nao substitui o da OS.
 *
 * O efeito era total: QUALQUER update de OS estourava
 *
 *   SQLSTATE[42703]: column "orcamento_id" of relation "ordens_servico"
 *   does not exist
 *
 * ou seja, nenhuma OS passava do diagnostico e a saga nao andava um passo. Os
 * 12 testes do SagaOutboxTest cobrem exatamente isso e estavam vermelhos desde
 * que foram escritos.
 *
 * Migration aditiva, nao alteracao da original: a tabela ja existe nos bancos
 * que rodaram o migrate, e reescrever o passado deixaria esses bancos para tras
 * em silencio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->uuid('orcamento_id')->nullable()->after('valor_total');
            $table->uuid('pagamento_id')->nullable()->after('orcamento_id');
            $table->uuid('execucao_id')->nullable()->after('pagamento_id');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->dropColumn(['orcamento_id', 'pagamento_id', 'execucao_id']);
        });
    }
};
