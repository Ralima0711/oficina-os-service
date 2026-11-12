<?php

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class VeiculoModel extends Model
{
    protected $table = 'veiculos';
    public $incrementing = false;
    protected $keyType = 'string';

    public const CREATED_AT = 'criado_em';
    public const UPDATED_AT = 'atualizado_em';

    protected $fillable = ['id', 'cliente_id', 'placa', 'marca', 'modelo', 'ano', 'cor'];

    protected $casts = ['ano' => 'integer'];
}
