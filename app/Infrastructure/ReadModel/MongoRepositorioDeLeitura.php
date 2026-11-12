<?php

namespace App\Infrastructure\ReadModel;

use App\Application\ReadModel\RepositorioDeLeituraInterface;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Read model no MongoDB (ADR-0009).
 *
 * Uma colecao, um documento por OS: status atual + historico.
 *
 * A idempotencia e por `messageId`, e o filtro do update ja exclui o
 * messageId que esta entrando (`$ne`). A versao anterior lia o documento,
 * decidia, e depois fazia tres escritas separadas — com duas replicas do
 * projetor, duas entregas simultaneas passavam as duas pela verificacao e o
 * historico duplicava. A janela nao era teorica: e a mesma forma do `SELECT`
 * seguido de `INSERT` que o contrato de eventos ja proibe na cobranca, pelo
 * mesmo motivo.
 *
 * Sao duas escritas, e nao uma, porque o upsert nao serve para idempotencia:
 * ele insere sempre que o filtro nao casa, e na reentrega o filtro nao casa
 * justamente porque o messageId ja esta no documento. Com o indice unico em
 * `osId`, isso virava 11000 — erro de escrita, nao reentrega — e o consumidor
 * mandava para a DLQ um evento que ja estava aplicado.
 *
 * Entao: update sem upsert quando o documento existe; insert quando nao
 * existe; e se o insert perder a corrida para o indice unico, o update e
 * tentado de novo e nao faz nada. O indice unico em `osId` continua sendo o
 * que garante um documento por OS, e e criado no arranque do worker.
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

        // Upsert NAO entra aqui. O filtro exclui o messageId que esta
        // entrando, entao na reentrega ele nao casa — e upsert insere sempre
        // que o filtro nao casa, sem olhar se o documento existe. O indice
        // unico em osId recusaria com 11000, e como nao e LostUpdateException
        // nem PDOException o consumidor mandaria para a DLQ um evento que ja
        // estava aplicado.
        //
        // A escrita e o update, e so ele. Documento que nao existe nao e
        // problema do update: quem cria e o insert abaixo.
        $atualizado = $this->raw(fn (Collection $colecao) => $colecao->updateOne(
            [
                'osId' => $evento['osId'],
                'messageIds' => ['$ne' => $evento['messageId']],
            ],
            [
                '$set' => $set,
                '$push' => [
                    'historico' => $entrada,
                    'messageIds' => $evento['messageId'],
                ],
            ],
        ));

        if ($atualizado->getMatchedCount() > 0) {
            return;
        }

        // Nao casou por um de dois motivos: o documento nao existe (primeira
        // entrega desta OS) ou o messageId ja esta la (reentrega). O insert
        // distingue os dois pelo indice unico, sem leitura previa.
        try {
            $documento = [
                'osId' => $evento['osId'],
                'sagaId' => $evento['sagaId'],
                'messageIds' => [$evento['messageId']],
                'historico' => [$entrada],
                'atualizadoEm' => $set['atualizadoEm'],
            ];

            // Mesma regra do update: evento sem status nao cria o campo, para
            // o documento nao nascer com um status que ninguem projetou.
            if ($evento['status'] !== null) {
                $documento['status'] = $evento['status'];
            }

            $this->raw(fn (Collection $colecao) => $colecao->insertOne($documento));
        } catch (BulkWriteException $e) {
            if (! $this->eChaveDuplicada($e)) {
                throw $e;
            }

            // Duas entregas do mesmo evento ao mesmo tempo: as duas passaram
            // pelo update (o documento ainda nao existia) e as duas tentaram
            // inserir. A que perdeu tenta o update de novo — agora o
            // documento existe e o messageId da vencedora esta la, entao o
            // filtro nao casa e nada acontece. Zero efeito, como a reentrega
            // deve ser.
            $this->raw(fn (Collection $colecao) => $colecao->updateOne(
                [
                    'osId' => $evento['osId'],
                    'messageIds' => ['$ne' => $evento['messageId']],
                ],
                [
                    '$set' => $set,
                    '$push' => [
                        'historico' => $entrada,
                        'messageIds' => $evento['messageId'],
                    ],
                ],
            ));
        }
    }

    /**
     * O insert perdeu a corrida para o indice unico em osId. Qualquer outro
     * erro de escrita nao e reentrega e tem que subir.
     */
    private function eChaveDuplicada(BulkWriteException $e): bool
    {
        foreach ($e->getWriteResult()->getWriteErrors() as $erro) {
            if ((int) $erro->getCode() === 11000) {
                return true;
            }
        }

        return false;
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
