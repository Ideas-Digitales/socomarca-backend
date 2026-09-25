<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Municipality extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'region_id',
        'code',
        'status',
        'random_key',
        'random_name',
    ];

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }
}
