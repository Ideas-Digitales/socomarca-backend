<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_method_id',
        'auth_code',
        'amount',
        'response_status',
        'response_message',
        'token',
        'paid_at',
        'status',
        'generate_random_doc_type',
        'status_check_attempts',
        'last_status_checked_at',
    ];

    protected $casts = [
        'response_message' => 'json',
        'last_status_checked_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
