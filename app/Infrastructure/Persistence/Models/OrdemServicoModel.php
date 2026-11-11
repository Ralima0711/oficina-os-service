<?php

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class OrdemServicoModel extends Model
{
    use HasUuids;

    protected $table = 'ordens_servico';
    public $incrementing = false;
    protected $keyType = 'string';

    // public, nao protected: o Model do Eloquent declara as duas como
    // `public const`, e PHP nao deixa a subclasse estreitar a visibilidade —
    // e erro fatal no carregamento da classe, nao aviso. Ficou escondido
    // enquanto o insert() era inalcancavel e o update usava DB::table().
    public const CREATED_AT = 'criada_em';
    public const UPDATED_AT = 'atualizada_em';

    protected $fillable = [
        'id',
        'cliente_id',
        'veiculo_id',
        'mecanico_id',
        'status',
        'version',
        'descricao_problema',
        'diagnostico',
        'valor_total',
        'orcamento_id',
        'pagamento_id',
        'execucao_id',
        'iniciada_em',
        'concluida_em',
    ];

    protected $casts = [
        'valor_total' => 'float',
        'version' => 'integer',
        'iniciada_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];
}
