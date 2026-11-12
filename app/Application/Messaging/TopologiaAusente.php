<?php

namespace App\Application\Messaging;

/**
 * Topologia ausente no broker.
 *
 * Nao e transitorio: tentar de novo nao faz a fila aparecer. O OutboxPublisher
 * conta a tentativa, e depois do teto a linha fica travada com o motivo escrito
 * em ultimo_erro — que e onde um alerta deve olhar. No consumidor ela derruba o
 * processo com codigo diferente de zero, em vez de deixar um worker mudo
 * reportando sucesso.
 *
 * Arquivo proprio porque o autoload e PSR-4: enquanto esta classe morava no fim
 * do MessagePublisher.php, ela so existia se alguem ja tivesse carregado o
 * MessagePublisher. Funcionava por acidente — quem a lancava era o proprio
 * arquivo. O MessageConsumer nao tem essa garantia, e daria "Class not found"
 * exatamente no caminho de erro, que e o pior lugar para descobrir isso.
 */
class TopologiaAusente extends \RuntimeException
{
}
