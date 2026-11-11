<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fecha o diagnostico e inicia a saga.
 *
 * itensDiagnosticados e OBRIGATORIO com pelo menos um item — e regra de
 * dominio, nao capricho de validacao. O Orchestrator recusa lista vazia
 * porque orcamento zerado faria a cobranca recusar com 422 e a OS ficaria
 * presa em AGUARDANDO_PAGAMENTO para sempre. Validar aqui devolve 422 com a
 * mensagem certa em vez de deixar estourar DomainException la dentro.
 *
 * As descricoes precisam existir no catalogo de precos do Billing; se nao
 * existirem, a saga nasce e compensa com motivo item_fora_do_catalogo. Isso
 * NAO se valida aqui: o catalogo e do Billing, e este servico nao consulta
 * banco de outro servico (ADR-0007).
 */
class ConcluirDiagnosticoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'mecanicoId' => ['required', 'uuid'],
            'diagnostico' => ['required', 'string', 'min:5', 'max:2000'],
            'itensDiagnosticados' => ['required', 'array', 'min:1'],
            'itensDiagnosticados.*.descricao' => ['required', 'string', 'max:255'],
            'itensDiagnosticados.*.tipo' => ['required', 'string', 'in:peca,servico'],
            'itensDiagnosticados.*.quantidade' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'itensDiagnosticados.required' => 'Diagnostico sem itens nao gera orcamento. Se nada ha a reparar, cancele a OS em vez de abrir a saga.',
            'itensDiagnosticados.min' => 'Diagnostico sem itens nao gera orcamento. Se nada ha a reparar, cancele a OS em vez de abrir a saga.',
        ];
    }
}
