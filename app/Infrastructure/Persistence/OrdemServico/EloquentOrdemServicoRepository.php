<?php

namespace App\Infrastructure\Persistence\OrdemServico;

use App\Domain\OrdemServico\Entities\OrdemServico;
use App\Domain\OrdemServico\Exceptions\LostUpdateException;
use App\Domain\OrdemServico\Repositories\OrdemServicoRepositoryInterface;
use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use App\Infrastructure\Persistence\Models\OrdemServicoModel;
use Illuminate\Support\Facades\DB;

/**
 * Implementação Eloquent do repositório da OS.
 *
 * Lock otimista — Reread Value (ADR-0011):
 * O UPDATE filtra por version = :versao_lida. Se nenhuma linha for afetada,
 * outro consumidor alterou o registro — aborta sem sobrescrever.
 */
class EloquentOrdemServicoRepository implements OrdemServicoRepositoryInterface
{
    public function findById(string $id): ?OrdemServico
    {
        $model = OrdemServicoModel::find($id);
        return $model ? $this->toEntity($model) : null;
    }

    public function findAll(): array
    {
        return OrdemServicoModel::all()
            ->map(fn ($m) => $this->toEntity($m))
            ->all();
    }

    public function save(OrdemServico $ordem): OrdemServico
    {
        if ($ordem->getId() === null) {
            return $this->insert($ordem);
        }

        return $this->updateWithOptimisticLock($ordem);
    }

    public function delete(string $id): void
    {
        OrdemServicoModel::where('id', $id)->delete();
    }

    // ── Reread Value ───────────────────────────────────────────────

    private function updateWithOptimisticLock(OrdemServico $ordem): OrdemServico
    {
        $versaoLida = $ordem->getVersion();

        $affected = DB::table('ordens_servico')
            ->where('id', $ordem->getId())
            ->where('version', $versaoLida)
            ->update([
                'status' => $ordem->getStatus()->value(),
                'version' => $versaoLida + 1,
                'mecanico_id' => $ordem->getMecanicoId(),
                'diagnostico' => $ordem->getDiagnostico(),
                'valor_total' => $ordem->getValorTotal(),
                'orcamento_id' => $ordem->getOrcamentoId(),
                'pagamento_id' => $ordem->getPagamentoId(),
                'execucao_id' => $ordem->getExecucaoId(),
                'iniciada_em' => $ordem->getIniciadaEm()?->format('Y-m-d H:i:s'),
                'concluida_em' => $ordem->getConcluidaEm()?->format('Y-m-d H:i:s'),
                'atualizada_em' => now(),
            ]);

        if ($affected === 0) {
            throw new LostUpdateException($ordem->getId(), $versaoLida);
        }

        $ordem->incrementVersion();
        return $ordem;
    }

    private function insert(OrdemServico $ordem): OrdemServico
    {
        OrdemServicoModel::create([
            'id' => $ordem->getId(),
            'cliente_id' => $ordem->getClienteId(),
            'veiculo_id' => $ordem->getVeiculoId(),
            'mecanico_id' => $ordem->getMecanicoId(),
            'status' => $ordem->getStatus()->value(),
            'version' => $ordem->getVersion(),
            'descricao_problema' => $ordem->getDescricao(),
            'diagnostico' => $ordem->getDiagnostico(),
            'valor_total' => $ordem->getValorTotal(),
            'orcamento_id' => $ordem->getOrcamentoId(),
            'pagamento_id' => $ordem->getPagamentoId(),
            'execucao_id' => $ordem->getExecucaoId(),
            'iniciada_em' => $ordem->getIniciadaEm()?->format('Y-m-d H:i:s'),
            'concluida_em' => $ordem->getConcluidaEm()?->format('Y-m-d H:i:s'),
        ]);

        return $ordem;
    }

    // ── Mapeamento Model → Entidade ────────────────────────────────

    private function toEntity(OrdemServicoModel $m): OrdemServico
    {
        return new OrdemServico(
            id: $m->id,
            clienteId: $m->cliente_id,
            veiculoId: $m->veiculo_id,
            mecanicoId: $m->mecanico_id,
            status: StatusOrdem::from($m->status),
            version: $m->version,
            descricaoProblema: $m->descricao_problema,
            diagnostico: $m->diagnostico,
            valorTotal: $m->valor_total,
            orcamentoId: $m->orcamento_id,
            pagamentoId: $m->pagamento_id,
            execucaoId: $m->execucao_id,
            iniciadaEm: $m->iniciada_em ? new \DateTimeImmutable($m->iniciada_em) : null,
            criadaEm: $m->criada_em ? new \DateTimeImmutable($m->criada_em) : null,
            atualizadaEm: $m->atualizada_em ? new \DateTimeImmutable($m->atualizada_em) : null,
            concluidaEm: $m->concluida_em ? new \DateTimeImmutable($m->concluida_em) : null,
        );
    }
}
