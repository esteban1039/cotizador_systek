<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class QuoteEmissionFile extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'emission_id';

    protected $keyType = 'string';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['content'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Crypt(base64(pdf)) con APP_KEY.
        return ['content' => 'encrypted'];
    }
}
