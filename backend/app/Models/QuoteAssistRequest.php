<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Propuesta de la IA para medir aceptación. Sin texto libre, respuesta del modelo ni precios.
 */
final class QuoteAssistRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'knowledge_ids' => 'array',
            'proposed_lines' => 'array',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
