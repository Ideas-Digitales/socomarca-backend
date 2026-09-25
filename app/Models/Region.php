<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'status',
        'random_key',
        'random_name',
    ];

    public function municipalities()
    {
        return $this->hasMany(Municipality::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }
}
