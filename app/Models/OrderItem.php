<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;
    protected $fillable = ['order_id', 'product_id', 'unit', 'price', 'quantity', 'subtotal', 'vat', 'total'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * VAT rate applied to the line, in percentage.
     *
     * @return float
     */
    public function getVatAttribute()
    {
        return (float) ($this->attributes['vat'] ?? 0);
    }

    /**
     * VAT amount of the line: the difference between the total and the net subtotal.
     *
     * @return float
     */
    public function getVatAmountAttribute()
    {
        return round($this->total - $this->subtotal, 0);
    }
}
