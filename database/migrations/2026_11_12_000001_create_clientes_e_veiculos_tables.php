<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As tabelas que o ADR-0007 decidiu em 20/10 e ninguem criou.
 *
 * "Cliente e veiculo ficam no OS Service, nao em um quarto servico: sao dados
 * do atendimento e nao tem ciclo de vida proprio que justifique o custo de
 * mais um repositorio, pipeline e banco."
 *
 * O recorte parou no scaffolding: as entidades e os value objects existiam, e
 * nada mais — sem tabela, sem repositorio, sem rota, sem teste, 0% de
 * cobertura. E ordens_servico ja guardava cliente_id e veiculo_id apontando
 * para um lugar nenhum.
 *
 * uuid e nao auto-incremento: ordens_servico.cliente_id e veiculo_id sao
 * colunas `uuid`, e o OrdemServico gera uuid4 na criacao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome');
            $table->string('tipo', 16);          // pessoa_fisica | pessoa_juridica
            $table->string('documento', 14);     // SEM mascara, como o VO guarda
            $table->string('telefone', 32);
            $table->string('email');
            $table->timestamp('criado_em')->useCurrent();
            $table->timestamp('atualizado_em')->nullable()->useCurrentOnUpdate();

            // Documento e a identidade natural: dois cadastros do mesmo CPF sao
            // o mesmo cliente, e a OS referencia um id so.
            $table->unique('documento');
        });

        Schema::create('veiculos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cliente_id');
            $table->string('placa', 8);
            $table->string('marca');
            $table->string('modelo');
            $table->unsignedSmallInteger('ano');
            $table->string('cor')->nullable();
            $table->timestamp('criado_em')->useCurrent();
            $table->timestamp('atualizado_em')->nullable()->useCurrentOnUpdate();

            // Placa e unica no pais inteiro, nao so por cliente.
            $table->unique('placa');

            // Chave estrangeira DE VERDADE: cliente e veiculo vivem no mesmo
            // banco e no mesmo servico, entao a integridade pode ser do banco.
            // Isso NAO vale para os ids que cruzam servico (orcamento_id,
            // pagamento_id, execucao_id em ordens_servico), que sao referencias
            // externas e nao podem ter FK — ADR-0007.
            $table->foreign('cliente_id')
                ->references('id')->on('clientes')
                ->restrictOnDelete();

            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('veiculos');
        Schema::dropIfExists('clientes');
    }
};
