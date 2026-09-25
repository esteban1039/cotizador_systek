<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Site extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'client_id', 'name', 'city', 'address'];
}
