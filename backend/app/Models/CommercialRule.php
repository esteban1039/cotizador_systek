<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CommercialRule extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'family';

    protected $fillable = ['family', 'minimum_margin_bps', 'max_discount_bps', 'review_above_cents'];
}
