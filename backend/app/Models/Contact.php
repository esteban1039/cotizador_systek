<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Contact extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'client_id', 'name', 'email', 'phone'];
}
