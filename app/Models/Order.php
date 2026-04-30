<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subtotal',
        'shipping_cost',
        'amount',
        'status',
        'order_meta'
    ];

    protected function casts(): array
    {
        return [
            'order_meta' => 'array'
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function orderDetails()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getAmountAttribute()
    {
        return round($this->attributes['amount'], 0);
    }

    public function getSubtotalAttribute()
    {
        return round($this->attributes['subtotal'], 0);
    }

    public function getShippingCostAttribute()
    {
        return round($this->attributes['shipping_cost'] ?? 0, 0);
    }

    public function getMunicipalityIdAttribute()
    {
        return $this->meta['municipality_id'] ?? null;
    }

    public function scopeSearchReport($query, $start, $end, $type = 'sales')
    {
        $query->whereBetween('orders.created_at', [$start, $end]);

        if ($type === 'top-municipalities') {
            // Usamos una subconsulta para obtener solo la comuna top por mes
            return DB::table(DB::raw('(
                SELECT
                    TO_CHAR(orders.created_at, \'YYYY-MM\') AS month,
                    municipalities.name AS municipality,
                    SUM(orders.amount) AS total_purchases,
                    COUNT(*) AS quantity
                FROM orders
                JOIN municipalities
                  ON (order_meta->\'address\'->>\'municipality_id\')::bigint = municipalities.id
                WHERE orders.status = \'completed\'
                  AND order_meta->\'address\'->>\'municipality_id\' IS NOT NULL
                  AND orders.created_at BETWEEN \'' . $start . '\' AND \'' . $end . '\'
                GROUP BY month, municipalities.name
                ORDER BY month, total_purchases DESC
            ) as t'))
                ->selectRaw('DISTINCT ON (month) month, municipality, total_purchases::bigint, quantity')
                ->orderBy('month')
                ->orderByDesc('total_purchases');
        }

        if ($type === 'transactions') {
            // Solo órdenes exitosas (ajusta el estado según tu lógica)
            return $query->select(
                DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
                DB::raw('COUNT(*) as transactions'),
                DB::raw('SUM(amount) as total')
            )
                ->where('status', 'completed')
                ->groupBy('month')
                ->orderBy('month');
        }

        if ($type === 'transactions-failed') {
            return $query->select(
                DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
                DB::raw('COUNT(*) as transactions_failed'),
                DB::raw('SUM(amount) as total')
            )
                ->where('status', 'failed')
                ->groupBy('month')
                ->orderBy('month');
        }

        if ($type === 'sales') {
            return $query->select(
                DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
                'user_id',
                DB::raw('SUM(amount) as total')
            )
                ->groupBy('month', 'user_id');
        }

        if ($type === 'revenue') {
            return $query->select(
                DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
                DB::raw('SUM(subtotal) as total_month')
            )
                ->groupBy('month')
                ->orderBy('month');
        }

        if ($type === 'top-customers') {
            return $query->select(
                DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
                'user_id',
                DB::raw('SUM(amount) as total_purchases'),
                DB::raw('COUNT(*) as quantity_purchases')
            )
                ->groupBy('month', 'user_id')
                ->orderBy('month')
                ->orderByDesc('total_purchases');
        }

        if ($type === 'top-products') {
            return $query->join('order_items', 'orders.id', '=', 'order_items.order_id')
                ->select(
                    DB::raw("TO_CHAR(orders.created_at, 'YYYY-MM') as month"),
                    'order_items.product_id',
                    DB::raw('SUM(order_items.quantity) as total_sales'),
                    DB::raw('SUM(order_items.price * order_items.quantity) as subtotal')
                )
                ->whereBetween('orders.created_at', [$start, $end])
                ->groupBy('month', 'order_items.product_id')
                ->orderBy('month')
                ->orderByDesc('total_sales');
        }

        if ($type === 'top-categories') {
            return $query
                ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->select(
                    DB::raw("TO_CHAR(orders.created_at, 'YYYY-MM') as month"),
                    'categories.id as category_id',
                    'categories.name as category',
                    DB::raw('SUM(order_items.quantity) as total_sales'),
                    DB::raw('SUM(order_items.price * order_items.quantity) as subtotal')
                )
                ->whereBetween('orders.created_at', [$start, $end])
                ->groupBy('month', 'categories.id', 'categories.name')
                ->orderBy('month')
                ->orderByDesc('total_sales');
        }

        // Por defecto, ventas por cliente y mes
        return $query->select(
            DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
            'user_id',
            DB::raw('SUM(amount) as total')
        )
            ->groupBy('month', 'user_id');
    }

    #[Scope]
    /**
     * Get orders which have the payment method associated through the
     * payment relationship
     *
     * @param Builder $query Eloquent query Builder
     * @param string $code Payment method code
     */
    protected function byPaymentMethodCode(Builder $query, string $code)
    {
        $query
            ->select('orders.*')
            ->join('payments', 'orders.id', '=', 'payments.order_id')
            ->join('payment_methods', 'payments.payment_method_id', '=', 'payment_methods.id')
            ->where('payment_methods.code', $code);

        return $query;
    }

    /**
     * Random documents relationship
     */
    public function randomDocuments()
    {
        return $this->morphToMany(RandomDocument::class, 'documentable', 'random_documentables', 'documentable_id', 'random_document_id');
    }
}
