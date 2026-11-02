<?php

namespace App\Domain\OrdemServico\Exceptions;

class LostUpdateException extends \RuntimeException
{
    public function __construct(string $osId, int $versaoLida)
    {
        parent::__construct(
            "Lost update detectado para OS {$osId}: versão lida = {$versaoLida}, "
            . "outro consumidor alterou o registro."
        );
    }
}
