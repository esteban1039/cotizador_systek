<?php

namespace App\Repositories\Contracts;

interface QuoteNumberRepository
{
    /**
     * Llamar dentro de la transacción de creación de la raíz; bloquea el
     * contador del año hasta el commit. Devuelve el consecutivo asignado.
     */
    public function allocate(int $year): int;
}
