<?php

namespace App\Application\ReadModel;

use App\Application\Messaging\Envelope;
use Illuminate\Support\Facades\Log;

/**
 * Projetor do read model de status e histórico (CQRS, ADR-0009).
 *
 * Consome os eventos da saga e mantém, no lado de leitura, o status atual
 * da OS e a linha do tempo. Não decide nada: só traduz evento em documento.
 * Quem manda no estado é o agregado, no lado de escrita.
 */
class ProjetorDeOrdens
{
    /**
     * Tipo do evento => status que ele projeta na OS.
     * Eventos fora deste mapa não alteram o status (só entram no histórico).
     */
    public const STATUS_POR_EVENTO = [
        'os.aberta'            => 'RECEBIDA',
        'orcamento.gerado'     => 'AGUARDANDO_APROVACAO',
        'orcamento.falhou'     => 'CANCELADA',
        'orcamento.aprovado'   => 'APROVADA',
        'orcamento.recusado'   => 'CANCELADA',
        'aprovacao.expirada'   => 'CANCELADA',
        'pagamento.confirmado' => 'EM_EXECUCAO',
        'pagamento.recusado'   => 'CANCELADA',
        'execucao.iniciada'    => 'EM_EXECUCAO',
        'execucao.concluida'   => 'FINALIZADA',
        'execucao.falhou'      => 'CANCELADA',
        'os.finalizada'        => 'FINALIZADA',
        'os.cancelada'         => 'CANCELADA',
    ];

    public function __construct(private readonly RepositorioDeLeituraInterface $repositorio) {}

    public function aplicar(Envelope $envelope): void
    {
        $osId = $this->extrairOsId($envelope);

        if ($osId === null) {
            Log::warning('Read model: evento sem osId ignorado', [
                'tipo' => $envelope->getType(),
                'messageId' => $envelope->getMessageId(),
            ]);
            return;
        }

        $status = self::STATUS_POR_EVENTO[$envelope->getType()] ?? null;

        $this->repositorio->registrarEvento([
            'osId' => $osId,
            'status' => $status,
            'messageId' => $envelope->getMessageId(),
            'tipo' => $envelope->getType(),
            'ocorridoEm' => $envelope->getOccurredAt(),
            'sagaId' => $envelope->getSagaId(),
            'dados' => $envelope->getBody(),
        ]);
    }

    private function extrairOsId(Envelope $envelope): ?string
    {
        $body = $envelope->getBody();
        $osId = $body['osId'] ?? null;

        return is_string($osId) && $osId !== '' ? $osId : null;
    }
}
