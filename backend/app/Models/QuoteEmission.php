<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class QuoteEmission extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issuer' => 'array',
            'bank_summary' => 'array',
            'clause_version_ids' => 'array',
            'emission_requires_authorization' => 'boolean',
            'revision_number' => 'integer',
            'pdf_size' => 'integer',
            'issued_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
