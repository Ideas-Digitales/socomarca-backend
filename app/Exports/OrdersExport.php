<?php

namespace App\Exports;

use App\Models\Order;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OrdersExport implements FromCollection, WithHeadings
{
    protected $start;
    protected $end;
    protected $client;
    protected $totalMin;
    protected $totalMax;
    protected $status; 

    public function __construct($start = null, $end = null, $client = null, $totalMin = null, $totalMax = null, $status = 'completed')
    {
        $this->start = $start ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $this->end = $end ?? now()->endOfMonth()->endOfDay()->toDateTimeString();
        $this->client = $client;
        $this->totalMin = $totalMin;
        $this->totalMax = $totalMax;
        $this->status = $status;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $query = Order::with('user')
            ->where('status', $this->status)
            ->whereBetween('created_at', [$this->start, $this->end]);

        if ($this->client) {
            $query->whereHas('user', function($q) {
                $q->where('name', $this->client);
            });
        }
        if ($this->totalMin !== null) {
            $query->where('amount', '>=', $this->totalMin);
        }
        if ($this->totalMax !== null) {
            $query->where('amount', '<=', $this->totalMax);
        }

        return $query->orderByDesc('created_at')->get()->map(function ($order) {
            return [
                'ID' => $order->id,
                'Cliente' => $order->user ? $order->user->name : null,
                'Monto' => $order->amount,
                'Fecha' => $order->created_at ? $order->created_at->format('Y-m-d H:i') : '',
                'Estado' => $order->status,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Cliente',
            'Monto',
            'Fecha',
            'Estado'
        ];
    }
}
