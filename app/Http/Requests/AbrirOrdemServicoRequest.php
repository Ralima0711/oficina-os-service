<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * clienteId e veiculoId sao `uuid` na tabela e o Postgres cobra o tipo: id
 * invalido aqui vira 500 la embaixo em vez de 422 aqui. Validar na borda e o
 * que transforma erro de banco em resposta util.
 */
class AbrirOrdemServicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'clienteId' => ['required', 'uuid'],
            'veiculoId' => ['required', 'uuid'],
            'descricaoProblema' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
