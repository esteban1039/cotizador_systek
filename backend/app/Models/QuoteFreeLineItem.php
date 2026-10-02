<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class QuoteFreeLineItem extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
