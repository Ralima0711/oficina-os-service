<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A placa fica com o value object Placa, que aceita o formato antigo
 * (AAA-1234) e o Mercosul (AAA1A23). Mesma razao do documento no cliente: a
 * regra mora num lugar so.
 */
class SalvarVeiculoRequest extends FormRequest
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
            'placa' => ['required', 'string', 'min:7', 'max:8'],
            'marca' => ['required', 'string', 'max:64'],
            'modelo' => ['required', 'string', 'max:64'],
            'ano' => ['required', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'cor' => ['nullable', 'string', 'max:32'],
        ];
    }
}
