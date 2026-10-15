<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_code',
        'user_id',
        'state',
        'is_blocked',
        'blocking_payment_id',
    ];

    protected $casts = [
        'state' => 'json'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Credit payment that blocked the line, until Random ERP invoices its NVV.
     */
    public function blockingPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'blocking_payment_id');
    }

    public function isBlocked(): bool
    {
        return (bool) $this->is_blocked;
    }

    public function block(Payment $payment): void
    {
        $this->update([
            'is_blocked' => true,
            'blocking_payment_id' => $payment->id,
        ]);
    }

    public function unblock(): void
    {
        $this->update([
            'is_blocked' => false,
            'blocking_payment_id' => null,
        ]);
    }
}
