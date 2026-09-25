<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PriceVersion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'catalog_item_id', 'version', 'price_cents', 'cost_cents', 'tax_bps', 'valid_from', 'valid_until', 'status'];
}
