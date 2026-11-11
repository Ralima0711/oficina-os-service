<?php

namespace Tests;

use Illuminate\Support\Facades\DB;

/**
 * Guarda para qualquer teste que use RefreshDatabase.
 *
 * O RefreshDatabase roda `migrate:fresh`: ele DERRUBA todas as tabelas da
 * conexao ativa e recria. Ele nao pergunta nada e nao olha para que banco
 * esta apontando.
 *
 * Em 11/11 isso apagou o banco de desenvolvimento: o `php artisan test` rodou
 * dentro do container do compose, que exporta DB_CONNECTION=pgsql e
 * DB_DATABASE=os_service, e a suite levou junto a OS que estava no meio de uma
 * saga. Num pipeline apontado para um banco de verdade, apaga o banco de
 * verdade.
 *
 * Duas condicoes, e so essas duas, sao aceitas:
 *
 *   - Postgres cujo nome de banco termine em `_test` ou `_teste`
 *   - nada mais
 *
 * SQLite nao serve para quem usa este trait: as colunas sao `uuid` e no SQLite
 * `uuid()` vira texto solto, entao o teste passaria sem validar tipo nenhum.
 * Foi assim que fixtures com 'm-1' sobreviveram meses.
 */
trait ExigeBancoDeTeste
{
    protected function exigirBancoDeTeste(): void
    {
        $driver = DB::connection()->getDriverName();
        $banco = (string) DB::connection()->getDatabaseName();

        if ($driver !== 'pgsql') {
            $this->markTestSkipped(
                "Este teste exige Postgres e a conexao atual e '{$driver}'. "
                . 'No SQLite ele passaria sem validar tipo de coluna nem transacao. '
                . 'Rode com DB_CONNECTION=pgsql e DB_DATABASE=<algo>_test.'
            );
        }

        // `_test` e `_teste`: o pipeline reutilizavel do infra sobe o Postgres
        // com POSTGRES_DB=oficina_teste, e o repositorio e todo em portugues.
        if (! str_ends_with($banco, '_test') && ! str_ends_with($banco, '_teste')) {
            $this->fail(
                "RECUSADO: a suite ia rodar migrate:fresh no banco '{$banco}', que nao "
                . "termina em '_test'. O RefreshDatabase DERRUBA todas as tabelas. "
                . 'Use um banco dedicado, terminado em _test ou _teste. '
                . 'Por exemplo DB_DATABASE=' . $banco . '_test.'
            );
        }
    }
}
