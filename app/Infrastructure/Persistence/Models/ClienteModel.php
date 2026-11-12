<?php

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class ClienteModel extends Model
{
    protected $table = 'clientes';
    public $incrementing = false;
    protected $keyType = 'string';

    // public, nao protected: o Model do Eloquent declara as duas como
    // `public const` e PHP nao deixa a subclasse estreitar a visibilidade.
    // Erro fatal de carregamento de classe — foi o que derrubou o
    // OrdemServicoModel em 11/11.
    public const CREATED_AT = 'criado_em';
    public const UPDATED_AT = 'atualizado_em';

    protected $fillable = ['id', 'nome', 'tipo', 'documento', 'telefone', 'email'];
}
