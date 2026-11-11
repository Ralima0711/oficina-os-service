<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * O documento NAO se valida aqui com regex.
 *
 * Quem decide se um CPF ou CNPJ e valido sao os value objects Cpf e Cnpj, que
 * conferem os digitos verificadores. Repetir a regra na borda criaria duas
 * fontes de verdade que divergem no dia em que alguem mexer numa so. Aqui so
 * se exige presenca e tamanho plausivel; o controller traduz o
 * InvalidArgumentException do dominio em 422.
 */
class SalvarClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:2', 'max:255'],
            'tipo' => ['required', 'string', 'in:pessoa_fisica,pessoa_juridica'],
            'documento' => ['required', 'string', 'min:11', 'max:18'],
            'telefone' => ['required', 'string', 'min:8', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
