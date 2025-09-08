<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_code',
        'branch_code',
        'warehouse_code',
        'name',
        'address',
        'phone',
        'priority',
        'is_active',
        'no_explosion',
        'no_lot',
        'no_location',
        'warehouse_type',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'no_explosion' => 'boolean',
        'no_lot' => 'boolean',
        'no_location' => 'boolean',
        'priority' => 'integer',
    ];

    public function productStocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    public function scopeDefault($query)
    {
        return $query->where('priority', 1)->where('is_active', true);
    }

    public function isDefault()
    {
        return $this->priority === 1;
    }
}