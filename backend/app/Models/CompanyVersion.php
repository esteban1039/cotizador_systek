<?php

namespace App\Models;

use Database\Factories\CompanyVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyVersion extends Model
{
    /** @use HasFactory<CompanyVersionFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['emission_requires_authorization' => true];

    /**
     * La cuenta bancaria nunca sale de este modelo por serialización: los
     * repositorios construyen arreglos públicos explícitos (nunca
     * `getAttributes()`/`toArray()` completos de esta tabla).
     *
     * @var list<string>
     */
    protected $hidden = ['bank_account'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'emission_requires_authorization' => 'boolean',
            // JSON cifrado con APP_KEY: {bank_name, account_type, account_number, account_holder|null}.
            'bank_account' => 'encrypted:array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
