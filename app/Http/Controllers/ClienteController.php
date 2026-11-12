<?php

namespace App\Http\Controllers;

use App\Domain\Cliente\Entities\Cliente;
use App\Domain\Cliente\Repositories\ClienteRepositoryInterface;
use App\Domain\Cliente\ValueObjects\Cnpj;
use App\Domain\Cliente\ValueObjects\Cpf;
use App\Domain\Cliente\ValueObjects\DocumentoFiscal;
use App\Http\Requests\SalvarClienteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Cliente, que o ADR-0007 colocou neste servico em 20/10 e ninguem
 * implementou. A OS referencia clienteId desde sempre; so nao havia como
 * cadastrar um.
 */
class ClienteController extends Controller
{
    public function __construct(
        private readonly ClienteRepositoryInterface $repositorio,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['dados' => $this->repositorio->findAll()]);
    }

    public function store(SalvarClienteRequest $request): JsonResponse
    {
        $dados = $request->validated();

        try {
            $documento = $this->documento($dados['tipo'], $dados['documento']);
        } catch (InvalidArgumentException $e) {
            // O value object e quem confere os digitos verificadores.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['documento' => [$e->getMessage()]],
            ], 422);
        }

        // Documento e identidade natural: o mesmo CPF nao vira dois clientes.
        $existente = $this->repositorio->findByDocumento($documento->getRaw());

        if ($existente !== null) {
            return response()->json([
                'erro' => 'Ja existe cliente com este documento.',
                'clienteId' => $existente->getId(),
            ], 409);
        }

        $cliente = Cliente::criar(
            $dados['nome'],
            $dados['tipo'],
            $documento,
            $dados['telefone'],
            $dados['email'],
        );

        $this->repositorio->save($cliente);

        Log::info('Cliente cadastrado', ['clienteId' => $cliente->getId(), 'tipo' => $cliente->getTipo()]);

        return response()->json($cliente, 201);
    }

    public function show(string $id): JsonResponse
    {
        $cliente = $this->repositorio->findById($id);

        return $cliente === null
            ? response()->json(['erro' => 'Cliente nao encontrado.'], 404)
            : response()->json($cliente);
    }

    public function update(SalvarClienteRequest $request, string $id): JsonResponse
    {
        $cliente = $this->repositorio->findById($id);

        if ($cliente === null) {
            return response()->json(['erro' => 'Cliente nao encontrado.'], 404);
        }

        $dados = $request->validated();

        try {
            $documento = $this->documento($dados['tipo'], $dados['documento']);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['documento' => [$e->getMessage()]],
            ], 422);
        }

        $outro = $this->repositorio->findByDocumento($documento->getRaw());

        if ($outro !== null && $outro->getId() !== $id) {
            return response()->json([
                'erro' => 'Ja existe outro cliente com este documento.',
                'clienteId' => $outro->getId(),
            ], 409);
        }

        $cliente->atualizar(
            $dados['nome'],
            $dados['tipo'],
            $documento,
            $dados['telefone'],
            $dados['email'],
        );

        $this->repositorio->save($cliente);

        return response()->json($cliente);
    }

    private function documento(string $tipo, string $valor): DocumentoFiscal
    {
        return $tipo === 'pessoa_juridica' ? new Cnpj($valor) : new Cpf($valor);
    }
}
