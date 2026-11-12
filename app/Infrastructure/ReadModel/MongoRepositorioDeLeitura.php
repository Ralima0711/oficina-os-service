<?php

namespace App\Infrastructure\ReadModel;

use App\Application\ReadModel\RepositorioDeLeituraInterface;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;

/**
 * Read model no MongoDB (ADR-0009).
 *
 * Uma colecao, um documento por OS: status atual + historico.
 *
 * A idempotencia e por `messageId`, e e feita em UMA operacao: um
 * `updateOne` com upsert cujo filtro ja exclui o messageId que esta entrando
 * (`$ne`). A versao anterior lia o documento, decidia, e depois fazia tres
 * escritas separadas — com duas replicas do projetor, duas entregas
 * simultaneas passavam as duas pela verificacao e o historico duplicava. A
 * janela nao era teorica: e a mesma forma do `SELECT` seguido de `INSERT` que
 * o contrato de eventos ja proibe na cobranca, pelo mesmo motivo.
 *
 * O indice unico em `osId` fecha a outra metade: sem ele, dois upserts
 * concorrentes para uma OS que ainda nao existe inserem dois documentos. Ele e
 * garantido no arranque do worker, nao a cada escrita.
 */
class MongoRepositorioDeLeitura implements RepositorioDeLeituraInterface
{
    private const COLECAO = 'ordens_servico_view';

    public function registrarEvento(array $evento): void
    {
        $entrada = [
            'tipo' => $evento['tipo'],
            'status' => $evento['status'],
            'ocorridoEm' => $evento['ocorridoEm'],
            'messageId' => $evento['messageId'],
            'dados' => $evento['dados'],
        ];

        $set = ['atualizadoEm' => now()->toIso8601String()];

        // Evento que nao mapeia status nao apaga o status que ja esta la: ele
        // so entra no historico. `aprovacao.expirada` chegando depois de
        // `os.cancelada` nao pode devolver a OS para um estado anterior.
        if ($evento['status'] !== null) {
            $set['status'] = $evento['status'];
        }

        $this->raw(fn (Collection $colecao) => $colecao->updateOne(
            [
                'osId' => $evento['osId'],
                // A reentrega do RabbitMQ cai aqui: o filtro nao casa, o
                // updateOne nao altera nada, e o upsert tambem nao insere
                // porque o documento existe. Zero efeito, sem leitura previa.
                'messageIds' => ['$ne' => $evento['messageId']],
            ],
            [
                '$set' => $set,
                '$setOnInsert' => [
                    'osId' => $evento['osId'],
                    'sagaId' => $evento['sagaId'],
                ],
                '$push' => [
                    'historico' => $entrada,
                    'messageIds' => $evento['messageId'],
                ],
            ],
            ['upsert' => true],
        ));
    }

    /**
     * Indice unico em osId. Idempotente: createIndex com a mesma definicao nao
     * faz nada. Chamado uma vez, no arranque do worker.
     */
    public function garantirIndices(): void
    {
        $this->raw(fn (Collection $colecao) => $colecao->createIndex(
            ['osId' => 1],
            ['unique' => true, 'name' => 'osId_unico'],
        ));
    }

    public function buscarPorOsId(string $osId): ?array
    {
        return $this->normalizar($this->colecao()->where('osId', $osId)->first());
    }

    public function listar(int $limite = 50): array
    {
        return $this->colecao()
            ->orderBy('atualizadoEm', 'desc')
            ->limit($limite)
            ->get()
            ->map(fn ($doc) => $this->normalizar($doc))
            ->all();
    }

    /**
     * O driver do MongoDB devolve stdClass, nao array. O read model e
     * consumido por controllers e testes que esperam array, entao a
     * conversao fica aqui, na borda, e nao em cada chamador.
     */
    private function normalizar(mixed $doc): ?array
    {
        if ($doc === null) {
            return null;
        }

        return json_decode(json_encode($doc), true);
    }

    private function raw(callable $operacao): mixed
    {
        return $this->colecao()->raw($operacao);
    }

    private function colecao()
    {
        return DB::connection('mongodb')->table(self::COLECAO);
    }
}
