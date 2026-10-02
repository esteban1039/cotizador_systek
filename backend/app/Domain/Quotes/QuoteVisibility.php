<?php

namespace App\Domain\Quotes;

use App\Models\User;

final class QuoteVisibility
{
    /**
     * @param  bool  $ownFreeLineCosts  Solo en `show`: el cotizador conserva `cost_cents` de sus líneas libres aún no vinculadas
     *                                  (dato propio, necesario para editar una devuelta). Totales y márgenes siguen ocultos.
     */
    public static function redact(array $snapshot, User $user, bool $ownFreeLineCosts = false): array
    {
        if ($user->role === 'quoter') {
            unset($snapshot['totals']['cost'], $snapshot['profit']);
            foreach ($snapshot['lines'] as &$line) {
                $keep = $ownFreeLineCosts && ($line['line_type'] ?? 'catalog') === 'free' && ! isset($line['linked_item']);
                if (! $keep) {
                    unset($line['cost_cents']);
                }
                unset($line['amounts']['cost']);
            }
            unset($line);
        }

        return $snapshot;
    }
}
