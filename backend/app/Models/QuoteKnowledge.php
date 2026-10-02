<?php

namespace App\Models;

use Database\Factories\QuoteKnowledgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Precedente depurado para el asistente IA. Sin dinero ni datos de cliente.
 * `search_vector` es una columna generada en PostgreSQL: nunca se escribe.
 */
final class QuoteKnowledge extends Model
{
    /** @use HasFactory<QuoteKnowledgeFactory> */
    use HasFactory;

    protected $table = 'quote_knowledge';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['search_vector'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'scrub_flags' => 'array',
            'issued' => 'boolean',
            'ai_assisted' => 'boolean',
            'trust' => 'decimal:2',
            'human_edit_ratio' => 'decimal:3',
            'source_revision' => 'integer',
            'captured_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
