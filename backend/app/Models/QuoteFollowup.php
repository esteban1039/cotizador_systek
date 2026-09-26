<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Evento de seguimiento comercial. Append-only: no se actualiza ni se borra.
 */
final class QuoteFollowup extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('Los eventos de seguimiento son inmutables.');
        });
        self::deleting(function (): never {
            throw new LogicException('Los eventos de seguimiento son inmutables.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
