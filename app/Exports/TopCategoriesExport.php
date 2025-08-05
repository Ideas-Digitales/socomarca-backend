<?php

namespace App\Exports;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TopCategoriesExport implements FromCollection, WithHeadings
{
    protected $start;
    protected $end;
    protected $totalMin;
    protected $totalMax;

    public function __construct($start = null, $end = null, $totalMin = null, $totalMax = null)
    {
        $this->start = $start ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $this->end = $end ?? now()->endOfMonth()->toDateString();
        $this->totalMin = $totalMin;
        $this->totalMax = $totalMax;
    }

    public function collection()
    {
        $categories = Category::select('categories.id', 'categories.name')
            ->leftJoin('products', 'products.category_id', '=', 'categories.id')
            ->leftJoin('order_items', function ($join) {
                $join->on('order_items.product_id', '=', 'products.id');
            })
            ->leftJoin('orders', function ($join) {
                $join->on('orders.id', '=', 'order_items.order_id')
                     ->where('orders.status', 'completed')
                     ->whereBetween('orders.created_at', [$this->start, $this->end]);
            })
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('COALESCE(SUM(order_items.price * order_items.quantity), 0) as total_ventas')
            ->orderByDesc('total_ventas')
            ->get();

        // Aplica filtros total_min y total_max si están definidos
        $categories = $categories->filter(function ($cat) {
            $pass = true;
            if ($this->totalMin !== null) {
                $pass = $pass && $cat->total_ventas >= $this->totalMin;
            }
            if ($this->totalMax !== null) {
                $pass = $pass && $cat->total_ventas <= $this->totalMax;
            }
            return $pass;
        })->values();

        // Agrega ranking
        $ranking = 1;
        return $categories->map(function ($category) use (&$ranking) {
            return [
                'Ranking' => $ranking++,
                'Categoría' => $category->name,
                'Total Ventas' => (int) $category->total_ventas,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Ranking',
            'Categoría',
            'Total Ventas',
        ];
    }
}
