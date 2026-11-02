<?php

namespace App\Application\Saga;

use App\Application\Messaging\Envelope;
use App\Application\Messaging\MessagePublisher;
use App\Domain\OrdemServico\Exceptions\LostUpdateException;
use App\Domain\OrdemServico\Repositories\OrdemServicoRepositoryInterface;
use App\Domain\OrdemServico\ValueObjects\StatusOrdem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Orquestrador da Saga — hospedado no OS Service.
 *
 * Foco atual: passo 4 (EnviarParaExecucao).
 * Publica o comando e consome os eventos de retorno da Execução.
 *
 * Idempotência transacional (Parte 5, regra 1): o messageId é inserido
 * dentro da mesma transação que aplica os efeitos.
 * Evento fora de ordem (Parte 5, regra 7): descarta, registra log e ack.
 */
class Orchestrator
{
    public function __construct(
        private OrdemServicoRepositoryInterface $osRepository,
        private MessagePublisher $publisher,
    ) {}

    // ── Passo 4: publicar EnviarParaExecucao ───────────────────────

    /**
     * Publica o comando EnviarParaExecucao para a fila da Execução.
     * Chamado quando o pagamento é confirmado (passo 3 → passo 4).
     */
    public function enviarParaExecucao(string $osId, string $sagaId): Envelope
    {
        $os = $this->osRepository->findById($osId);

        if ($os === null) {
            throw new \DomainException("OS não encontrada: {$osId}");
        }

        if (!$os->getStatus()->equals(StatusOrdem::AGUARDANDO_PAGAMENTO)) {
            throw new \DomainException(
                "OS {$osId} não está em AGUARDANDO_PAGAMENTO (atual: {$os->getStatus()->value()})"
            );
        }

        $body = [
            'osId' => $osId,
            'orcamentoId' => $os->getOrcamentoId(),
            'prioridade' => 1,
            'itens' => [],
        ];

        $envelope = Envelope::create(
            type: 'EnviarParaExecucao',
            body: $body,
            sagaId: $sagaId,
        );

        $this->publisher->publishCommand('execucao.enviar-para-execucao', $envelope);

        Log::info('Saga: EnviarParaExecucao publicado', [
            'osId' => $osId,
            'sagaId' => $sagaId,
            'messageId' => $envelope->getMessageId(),
        ]);

        return $envelope;
    }

    // ── Consumir execucao.iniciada ─────────────────────────────────

    public function onExecucaoIniciada(Envelope $env): void
    {
        $body = $env->getBody();
        $osId = $body['osId'] ?? null;
        $execucaoId = $body['execucaoId'] ?? null;

        if (!$osId || !$execucaoId) {
            Log::warning('Saga: execucao.iniciada sem osId ou execucaoId', $body);
            return;
        }

        $this->processarTransacao($env, $osId, function ($os) use ($execucaoId, $body) {
            if (!$os->getStatus()->equals(StatusOrdem::EM_EXECUCAO)) {
                throw new \DomainException(
                    "execucao.iniciada recebida mas OS está em {$os->getStatus()->value()}"
                );
            }
            $os->setExecucaoId($execucaoId);
            if (isset($body['mecanicoId'])) {
                Log::info('Saga: mecânico puxou a OS', [
                    'osId' => $os->getId(),
                    'mecanicoId' => $body['mecanicoId'],
                ]);
            }
        });
    }

    // ── Consumir execucao.concluida ────────────────────────────────

    public function onExecucaoConcluida(Envelope $env): void
    {
        $body = $env->getBody();
        $osId = $body['osId'] ?? null;

        if (!$osId) {
            Log::warning('Saga: execucao.concluida sem osId', $body);
            return;
        }

        $this->processarTransacao($env, $osId, function ($os) {
            if (!$os->getStatus()->equals(StatusOrdem::EM_EXECUCAO)) {
                throw new \DomainException(
                    "execucao.concluida recebida mas OS está em {$os->getStatus()->value()}"
                );
            }
            $os->finalizar();
        });

        $this->anunciarEncerramento($osId, $env->getSagaId(), 'FINALIZADA');
    }

    // ── Consumir execucao.falhou ───────────────────────────────────

    public function onExecucaoFalhou(Envelope $env): void
    {
        $body = $env->getBody();
        $osId = $body['osId'] ?? null;

        if (!$osId) {
            Log::warning('Saga: execucao.falhou sem osId', $body);
            return;
        }

        $this->processarTransacao($env, $osId, function ($os) {
            if (!$os->getStatus()->equals(StatusOrdem::EM_EXECUCAO)) {
                throw new \DomainException(
                    "execucao.falhou recebida mas OS está em {$os->getStatus()->value()}"
                );
            }
            $os->cancelarPorFalhaExecucao();
        });

        $this->anunciarEncerramento($osId, $env->getSagaId(), 'CANCELADA', $body['motivo'] ?? null);

        Log::info('Saga: compensação iniciada por execucao.falhou', [
            'osId' => $osId,
            'motivo' => $body['motivo'] ?? '',
        ]);
    }

    // ── Núcleo transacional ────────────────────────────────────────

    /**
     * Processa um evento dentro de uma transação Postgres:
     * 1. Insere messageId (idempotência)
     * 2. Carrega a OS
     * 3. Confere saga e estado (regra 7)
     * 4. Aplica a transição
     * 5. Salva com Reread Value
     */
    private function processarTransacao(Envelope $env, string $osId, callable $acao): void
    {
        DB::transaction(function () use ($env, $osId, $acao) {
            $jaProcessada = DB::table('mensagens_processadas')
                ->where('message_id', $env->getMessageId())
                ->exists();

            if ($jaProcessada) {
                Log::info('Saga: mensagem duplicada descartada', [
                    'messageId' => $env->getMessageId(),
                    'type' => $env->getType(),
                ]);
                return;
            }

            $os = $this->osRepository->findById($osId);

            if ($os === null) {
                Log::warning('Saga: OS não encontrada para evento', [
                    'osId' => $osId,
                    'type' => $env->getType(),
                ]);
                return;
            }

            if ($os->getStatus()->isTerminal()) {
                Log::info('Saga: evento para OS terminal descartado', [
                    'osId' => $osId,
                    'status' => $os->getStatus()->value(),
                    'type' => $env->getType(),
                ]);
                return;
            }

            $acao($os);

            try {
                $this->osRepository->save($os);
            } catch (LostUpdateException $e) {
                Log::warning('Saga: lost update, reprocessando', [
                    'osId' => $osId,
                    'messageId' => $env->getMessageId(),
                ]);
                throw $e;
            }

            DB::table('mensagens_processadas')->insert([
                'message_id' => $env->getMessageId(),
                'saga_id' => $env->getSagaId(),
                'tipo' => $env->getType(),
                'processado_em' => now(),
            ]);
        });
    }

    // ── Eventos de anúncio ─────────────────────────────────────────

    private function anunciarEncerramento(
        string $osId,
        string $sagaId,
        string $estadoFinal,
        ?string $motivo = null,
    ): void {
        $body = [
            'osId' => $osId,
            'sagaId' => $sagaId,
            'estadoFinal' => $estadoFinal,
            'ocorridoEm' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($motivo !== null) {
            $body['motivo'] = $motivo;
        }

        $routingKey = $estadoFinal === 'CANCELADA' ? 'os.cancelada' : 'os.finalizada';

        $envelope = Envelope::create(
            type: $routingKey,
            body: $body,
            sagaId: $sagaId,
        );

        $this->publisher->publishEvent($envelope);

        DB::table('saga_estado')
            ->where('saga_id', $sagaId)
            ->update([
                'status' => $estadoFinal === 'CANCELADA' ? 'CANCELADA' : 'FINALIZADA',
                'finalizada_em' => now(),
            ]);
    }
}
