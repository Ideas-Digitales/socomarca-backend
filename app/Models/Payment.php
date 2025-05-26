<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;
    protected $fillable = ['order_id', 'payment_method_id', 'auth_code', 'amount', 'response_status', 'response_message'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethods()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
