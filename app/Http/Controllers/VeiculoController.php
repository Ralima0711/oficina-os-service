<?php

namespace App\Http\Controllers;

use App\Domain\Cliente\Repositories\ClienteRepositoryInterface;
use App\Domain\Veiculo\Entities\Veiculo;
use App\Domain\Veiculo\Repositories\VeiculoRepositoryInterface;
use App\Domain\Veiculo\ValueObjects\Placa;
use App\Http\Requests\SalvarVeiculoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class VeiculoController extends Controller
{
    public function __construct(
        private readonly VeiculoRepositoryInterface $repositorio,
        private readonly ClienteRepositoryInterface $clientes,
    ) {}

    public function index(): JsonResponse
    {
        $clienteId = request()->query('clienteId');

        $dados = is_string($clienteId) && $clienteId !== ''
            ? $this->repositorio->findDoCliente($clienteId)
            : $this->repositorio->findAll();

        return response()->json(['dados' => $dados]);
    }

    public function store(SalvarVeiculoRequest $request): JsonResponse
    {
        $dados = $request->validated();

        // Cliente e veiculo vivem no MESMO servico, entao conferir aqui nao
        // cruza fronteira nenhuma — e o banco ainda tem a FK por baixo.
        if ($this->clientes->findById($dados['clienteId']) === null) {
            return response()->json([
                'message' => 'Cliente nao encontrado.',
                'errors' => ['clienteId' => ['Cliente nao encontrado.']],
            ], 422);
        }

        try {
            $placa = new Placa($dados['placa']);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['placa' => [$e->getMessage()]],
            ], 422);
        }

        if ($this->repositorio->findByPlaca((string) $placa) !== null) {
            return response()->json(['erro' => 'Ja existe veiculo com esta placa.'], 409);
        }

        $veiculo = Veiculo::criar(
            $dados['clienteId'],
            $placa,
            $dados['marca'],
            $dados['modelo'],
            (int) $dados['ano'],
            $dados['cor'] ?? null,
        );

        $this->repositorio->save($veiculo);

        Log::info('Veiculo cadastrado', [
            'veiculoId' => $veiculo->getId(),
            'clienteId' => $veiculo->getClienteId(),
            'placa' => (string) $placa,
        ]);

        return response()->json($veiculo, 201);
    }

    public function show(string $id): JsonResponse
    {
        $veiculo = $this->repositorio->findById($id);

        return $veiculo === null
            ? response()->json(['erro' => 'Veiculo nao encontrado.'], 404)
            : response()->json($veiculo);
    }
}
