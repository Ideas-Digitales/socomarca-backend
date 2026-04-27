<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_code',
        'user_id',
        'state',
        'is_blocked',
    ];

    protected $casts = [
        'state' => 'json'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
