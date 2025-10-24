<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'unit',
        'stock',
        'reserved_stock',
        'min_stock',
    ];

    protected $casts = [
        'stock' => 'integer',
        'reserved_stock' => 'integer',
        'min_stock' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function getAvailableStockAttribute()
    {
        return $this->stock - $this->reserved_stock;
    }

    public function scopeByWarehouse($query, $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByUnit($query, $unit)
    {
        return $query->where('unit', $unit);
    }

    public function scopeWithStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    public function scopeWithAvailableStock($query)
    {
        return $query->whereRaw('stock > reserved_stock');
    }

    public function reserveStock($quantity)
    {
        if ($this->available_stock >= $quantity) {
            $this->reserved_stock += $quantity;
            $this->save();
            return true;
        }
        return false;
    }

    public function releaseStock($quantity)
    {
        $this->reserved_stock = max(0, $this->reserved_stock - $quantity);
        $this->save();
    }

    public function reduceStock($quantity)
    {
        if ($this->stock >= $quantity) {
            $this->stock -= $quantity;
            $this->reserved_stock = max(0, $this->reserved_stock - $quantity);
            $this->save();
            return true;
        }
        return false;
    }
}