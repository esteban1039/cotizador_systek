<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CatalogItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'sku', 'description', 'family', 'unit', 'active', 'is_demo'];

    public function versions(): HasMany
    {
        return $this->hasMany(PriceVersion::class)->orderByDesc('version');
    }
}
