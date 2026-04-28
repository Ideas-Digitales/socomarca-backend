<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RandomDocument extends Model
{
    protected $primaryKey = 'idmaeedo';

    public $incrementing = false;

    protected $fillable = [
        'idmaeedo',
        'type',
        'document',
    ];

    protected $casts = [
        'document' => 'array',
    ];
}
