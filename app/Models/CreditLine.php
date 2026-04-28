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

    public function isBlocked(): bool
    {
        return (bool) $this->is_blocked;
    }

    public function block(): void
    {
        $this->update(['is_blocked' => true]);
    }

    public function unblock(): void
    {
        $this->update(['is_blocked' => false]);
    }
}
