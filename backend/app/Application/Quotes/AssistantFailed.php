<?php

namespace App\Application\Quotes;

use RuntimeException;

/** El mensaje es genérico; `outcome` es la razón interna (invalid_output|provider_error|timeout). */
final class AssistantFailed extends RuntimeException
{
    public function __construct(public readonly string $outcome = 'provider_error')
    {
        parent::__construct('El asistente no está disponible.');
    }
}
