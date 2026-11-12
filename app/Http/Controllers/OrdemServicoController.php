<?php

namespace App\Http\Controllers;

use App\Application\ReadModel\RepositorioDeLeituraInterface;
use App\Application\Saga\Orchestrator;
use App\Domain\OrdemServico\Entities\OrdemServico;
use App\Domain\OrdemServico\Repositories\OrdemServicoRepositoryInterface;
use App\Http\Requests\AbrirOrdemServicoRequest;
use App\Http\Requests\ConcluirDiagnosticoRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * A porta de entrada da saga.
 *
 * O Orchestrator diz que iniciarSaga "e o unico ponto de entrada que nao vem
 * de um evento — vem da API, quando o mecanico fecha o diagnostico". Este
 * controller e essa API; ate 11/11 ela nao existia e a saga so disparava por
 * tinker.
 *
 * Nao ha regra de negocio aqui: a entidade manda nas transicoes e o
 * Orchestrator manda na saga. O controller traduz HTTP para dominio e de
 * volta, e so.
 */
class OrdemServicoController extends Controller
{
    public function __construct(
        private readonly OrdemServicoRepositoryInterface $repositorio,
        private readonly Orchestrator $saga,
    ) {}

    /**
     * Abre a OS. Status RECEBIDA; a saga ainda nao comeca.
     */
    public function store(AbrirOrdemServicoRequest $request): JsonResponse
    {
        $dados = $request->validated();

        $os = OrdemServico::criar(
            $dados['clienteId'],
            $dados['veiculoId'],
            $dados['descricaoProblema'],
        );

        $this->repositorio->save($os);

        Log::info('OS aberta', ['osId' => $os->getId(), 'clienteId' => $os->getClienteId()]);

        return response()->json($this->representar($os), 201);
    }

    /**
     * Fecha o diagnostico e INICIA A SAGA.
     *
     * 202 e nao 200: o que esta pronto e a decisao do mecanico. O orcamento
     * vem depois, por mensagem, e pode ate falhar — o cliente acompanha pelo
     * GET.
     */
    public function concluirDiagnostico(ConcluirDiagnosticoRequest $request, string $id): JsonResponse
    {
        $os = $this->repositorio->findById($id);

        if ($os === null) {
            return response()->json(['erro' => 'Ordem de servico nao encontrada.'], 404);
        }

        $dados = $request->validated();

        try {
            $os->iniciarDiagnostico($dados['mecanicoId']);
            $os->setDiagnostico($dados['diagnostico']);
            $this->repositorio->save($os);

            // A partir daqui e o orquestrador: ele faz o T2 e grava o
            // GerarOrcamento no outbox, na mesma transacao.
            $sagaId = $this->saga->iniciarSaga($id, $dados['itensDiagnosticados']);
        } catch (DomainException $e) {
            // Transicao invalida para o estado atual da OS, ou diagnostico sem
            // itens. E conflito com o estado, nao erro de formato: 409.
            Log::info('Diagnostico recusado pelo dominio', [
                'osId' => $id,
                'status' => $os->getStatus()->value(),
                'motivo' => $e->getMessage(),
            ]);

            return response()->json([
                'erro' => $e->getMessage(),
                'status' => $os->getStatus()->value(),
            ], 409);
        }

        Log::info('Saga aberta pela API', ['osId' => $id, 'sagaId' => $sagaId]);

        return response()->json([
            'sagaId' => $sagaId,
        ] + $this->representar($this->repositorio->findById($id)), 202);
    }

    public function show(string $id): JsonResponse
    {
        $os = $this->repositorio->findById($id);

        if ($os === null) {
            return response()->json(['erro' => 'Ordem de servico nao encontrada.'], 404);
        }

        return response()->json($this->representar($os));
    }

    /**
     * Lado de leitura do CQRS (ADR-0009): status e historico vem do MongoDB.
     *
     * E eventualmente consistente, e a resposta diz com o que o cliente pode
     * se orientar.
     *
     * O campo chamava-se `commandId` e devolvia o messageId do ultimo evento
     * projetado. O nome prometia uma comparacao que nao existe: o cliente
     * recebe `sagaId` no 202 do POST /diagnostico, nunca um messageId, entao
     * nao havia com o que comparar. Agora sao dois campos com nome honesto:
     * `sagaId`, que o cliente TEM e pode conferir, e `ultimoEventoId` com
     * `atualizadoEm`, que dizem ate onde a projecao chegou.
     */
    public function historico(string $id, RepositorioDeLeituraInterface $leitura): JsonResponse
    {
        $doc = $leitura->buscarPorOsId($id);

        if ($doc === null) {
            return response()->json([
                'erro' => 'Historico ainda nao projetado para esta ordem de servico.',
            ], 404);
        }

        $messageIds = $doc['messageIds'] ?? [];

        return response()->json([
            'osId' => $doc['osId'],
            // O documento pode nao ter status: o primeiro evento de uma OS
            // pode ser um que so entra no historico e nao projeta estado.
            'status' => $doc['status'] ?? null,
            'sagaId' => $doc['sagaId'] ?? null,
            'ultimoEventoId' => $messageIds === [] ? null : end($messageIds),
            'atualizadoEm' => $doc['atualizadoEm'] ?? null,
            'historico' => $doc['historico'] ?? [],
        ]);
    }

    /** @return array<string, mixed> */
    private function representar(OrdemServico $os): array
    {
        return [
            'id' => $os->getId(),
            'status' => $os->getStatus()->value(),
            'version' => $os->getVersion(),
            'clienteId' => $os->getClienteId(),
            'veiculoId' => $os->getVeiculoId(),
            'mecanicoId' => $os->getMecanicoId(),
            'descricaoProblema' => $os->getDescricao(),
            'diagnostico' => $os->getDiagnostico(),
            'valorTotal' => $os->getValorTotal(),
            'orcamentoId' => $os->getOrcamentoId(),
            'pagamentoId' => $os->getPagamentoId(),
            'execucaoId' => $os->getExecucaoId(),
        ];
    }
}
