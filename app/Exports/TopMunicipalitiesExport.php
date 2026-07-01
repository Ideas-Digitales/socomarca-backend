<?php

namespace App\Exports;

use App\Models\Order;
use App\Models\Municipality;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TopMunicipalitiesExport implements FromCollection, WithHeadings
{
    protected $start;
    protected $end;
    protected $totalMin;
    protected $totalMax;

    public function __construct($start = null, $end = null, $totalMin = null, $totalMax = null)
    {
        $this->start = $start ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $this->end = $end ?? now()->endOfMonth()->endOfDay()->toDateTimeString();
        $this->totalMin = $totalMin;
        $this->totalMax = $totalMax;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $orders = Order::where('status', 'completed')
            ->whereBetween('created_at', [$this->start, $this->end])
            ->get();

        // Agrupa por mes
        $byMonth = $orders->groupBy(function ($order) {
            return $order->created_at ? $order->created_at->format('Y-m') : null;
        });

        $topMunicipalities = $byMonth->map(function ($ordersOfMonth, $month) {
            // Agrupa por comuna dentro del mes
            $byMunicipality = $ordersOfMonth->groupBy(function ($order) {
                return $order->order_meta['address']['municipality_id'] ?? null;
            });

            // Calcula ventas por comuna
            $municipalitySales = $byMunicipality->map(function ($orders, $municipalityId) use ($month) {
                return [
                    'municipality_id' => $municipalityId,
                    'month' => $month,
                    'total_sales' => $orders->sum('amount'),
                    'total_orders' => $orders->count(),
                ];
            });

            // Devuelve solo la comuna con más ventas de ese mes
            return $municipalitySales->sortByDesc('total_sales')->first();
        })->filter();

        // Aplica filtros de total_min y total_max
        $topMunicipalities = $topMunicipalities->filter(function ($item) {
            $pass = true;
            if ($this->totalMin !== null) {
                $pass = $pass && $item['total_sales'] >= $this->totalMin;
            }
            if ($this->totalMax !== null) {
                $pass = $pass && $item['total_sales'] <= $this->totalMax;
            }
            return $pass;
        });

        // Obtiene nombres de comunas
        $municipalityIds = $topMunicipalities->pluck('municipality_id')->filter()->unique()->all();
        $municipalities = \App\Models\Municipality::whereIn('id', $municipalityIds)->get()->keyBy('id');

        return $topMunicipalities->map(function ($item) use ($municipalities) {
            $municipality = $municipalities->get($item['municipality_id']);
            return [
                'Comuna' => $municipality ? $municipality->name : 'Desconocida',
                'Mes' => $item['month'],
                'Total ventas' => $item['total_sales'],
                'Cantidad de órdenes' => $item['total_orders'],
            ];
        })->values();
    }

    public function headings(): array
    {
        return [
            'Comuna',
            'Mes',
            'Total ventas',
            'Cantidad de órdenes'
        ];
    }
}
