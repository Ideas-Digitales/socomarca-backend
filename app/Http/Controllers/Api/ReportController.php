<?php

namespace App\Http\Controllers\Api;

use App\Exports\CategoriesExport;
use App\Exports\OrdersExport;
use App\Exports\OrdersReportExport;
use App\Exports\TopMunicipalitiesExport;
use App\Exports\TopProductsExport;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Exports\ClientsReportExport;
use App\Exports\TopCategoriesExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /**
     * Retorna el nombre del archivo de descarga al exportar los reportes
     * Útil para testing
     *
     * @param string $baseFileName
     * @param ?string $extension
     * @return string
     */
    public function getDownloadFileName(string $baseFileName, ?string $extension = null): string
    {
        $fileName = $baseFileName . now()->format('Ymd_His');

        if (!empty($extension)) {
            $fileName .= '.xlsx';
        }

        return $fileName;
    }

    public function report(Request $request)
    {
        // Validación de filtros
        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'client' => 'nullable|string|exists:users,name',
            'type' => 'nullable|string',
            'total_min' => 'nullable|numeric|min:0',
            'total_max' => 'nullable|numeric|gte:total_min',
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->toDateString();
        $type = $validated['type'] ?? 'sales';
        $client = $validated['client'] ?? null;
        $totalMin = $validated['total_min'] ?? null;
        $totalMax = $validated['total_max'] ?? null;

        if ($type === 'top-municipalities') {
            $topMunicipalities = \App\Models\Order::searchReport($start, $end, 'top-municipalities')->get();

            // Filtro por monto
            if ($totalMin !== null) {
                $topMunicipalities = $topMunicipalities->filter(fn($item) => $item->total_purchases >= $totalMin);
            }
            if ($totalMax !== null) {
                $topMunicipalities = $topMunicipalities->filter(fn($item) => $item->total_purchases <= $totalMax);
            }

            $total_purchases = $topMunicipalities->sum(fn($item) => (int) $item->total_purchases);
            $quantity = $topMunicipalities->sum(fn($item) => (int) $item->quantity);

            return response()->json([
                'top_municipalities' => $topMunicipalities->values(),
                'total_purchases' => $total_purchases,
                'quantity' => $quantity,
            ]);
        }

        // Solo para los tipos que usan Eloquent y relaciones:
        $ordersQuery = \App\Models\Order::searchReport($start, $end, $type);

        // Aplica filtro de cliente si corresponde
        if ($client) {
            $ordersQuery = $ordersQuery->whereHas('user', function($q) use ($client) {
                $q->where('name', $client);
            });
        }

        $orders = $ordersQuery->with('user')->get();

        if ($type === 'top-customers') {
            $userIds = $orders->pluck('user_id')->unique()->all();
            $users = \App\Models\User::whereIn('id', $userIds)->get()->keyBy('id');

            $months = $orders->pluck('month')->unique()->sort()->values()->all();

            $topClients = [];
            $totalSales = 0;

            foreach ($months as $month) {
                $clientsMonth = $orders->where('month', $month);
                $top = $clientsMonth->sortByDesc('total_purchases')->first();
                if ($top) {
                    $user = $users->get($top->user_id);
                    $topClients[] = [
                        'month' => $month,
                        'customer' => $user ? $user->name : null,
                        'total_purchases' => (int)$top->total_purchases,
                        'quantity_purchases' => (int)$top->quantity_purchases,
                    ];
                    $totalSales += $top->total_purchases;
                }
            }

            return response()->json([
                'top_customers' => $topClients,
                'total_sales' => $totalSales
            ]);
        }

        if ($type === 'transactions') {
            $chart = [];
            foreach ($orders as $order) {
                $chart[] = [
                    'month' => $order->month,
                    'transactions' => (int)$order->transactions,
                    'total' => (int)$order->total,
                ];
            }
            return response()->json([
                'chart' => $chart,
            ]);
        }

        if ($type === 'transactions-failed') {
            $chart = [];
            $filteredOrders = $orders;

            // Filtro por monto
            if ($totalMin !== null) {
                $filteredOrders = $filteredOrders->filter(fn($item) => $item->total >= $totalMin);
            }
            if ($totalMax !== null) {
                $filteredOrders = $filteredOrders->filter(fn($item) => $item->total <= $totalMax);
            }

            foreach ($filteredOrders as $order) {
                $chart[] = [
                    'month' => $order->month,
                    'failed_transactions' => (int)$order->transactions_failed,
                    'total_failed' => (float)$order->total,
                ];
            }
            return response()->json([
                'chart' => $chart,
            ]);
        }

        if ($type === 'top-products') {
            $productIds = $orders->pluck('product_id')->unique()->all();
            $products = \App\Models\Product::whereIn('id', $productIds)->get()->keyBy('id');
            $months = $orders->pluck('month')->unique()->sort()->values()->all();

            $topProducts = [];
            $total_sales = 0;
            foreach ($months as $month) {
                $productsMonth = $orders->where('month', $month);

                // Filtro por monto
                if ($totalMin !== null) {
                    $productsMonth = $productsMonth->filter(fn($item) => $item->subtotal >= $totalMin);
                }
                if ($totalMax !== null) {
                    $productsMonth = $productsMonth->filter(fn($item) => $item->subtotal <= $totalMax);
                }

                $top = $productsMonth->sortByDesc('total_sales')->first();
                if ($top) {
                    $product = $products->get($top->product_id);
                    $topProducts[] = [
                        'month' => $month,
                        'product' => $product ? $product->name : null,
                        'total' => (int)$top->subtotal,
                    ];
                    $total_sales += (int)$top->subtotal;
                }
            }

            return response()->json([
                'top_products' => $topProducts,
                'total_sales' => $total_sales,
            ]);
        }

        if ($type === 'revenue') {
            $revenues = [];
            $total_revenue = 0;
            foreach ($orders as $order) {
                $revenues[] = [
                    'month' => $order->month,
                    'revenue' => (int)$order->total_month
                ];
                $total_revenue += (int)$order->total_month;
            }
            return response()->json([
                'revenues' => $revenues,
                'total_revenue' => $total_revenue,
            ]);
        }

        if ($type === 'top-categories') {
            $months = $orders->pluck('month')->unique()->sort()->values()->all();

            $topCategories = [];
            foreach ($months as $month) {
                $categoriesMonth = $orders->where('month', $month);

                // Filtro por monto
                if ($totalMin !== null) {
                    $categoriesMonth = $categoriesMonth->filter(fn($item) => $item->subtotal >= $totalMin);
                }
                if ($totalMax !== null) {
                    $categoriesMonth = $categoriesMonth->filter(fn($item) => $item->subtotal <= $totalMax);
                }

                $top = $categoriesMonth->sortByDesc('total_sales')->first();
                if ($top) {
                    $topCategories[] = [
                        'month' => $month,
                        'category' => $top->category,
                        'total' => (int)$top->subtotal,
                    ];
                }
            }

            $totalSales = collect($topCategories)->sum('total');
            $averageSales = count($topCategories) > 0 ? round($totalSales / count($topCategories), 0) : 0;

            return response()->json([
                'top_categories' => $topCategories,
                'total_sales' => $totalSales,
                'average_sales' => $averageSales
            ]);
        }

        // For sales and buyers - AQUÍ SE APLICA EL FILTRO DE TOTALES POR CLIENTE
        $months = $orders->pluck('month')->unique()->sort()->values()->all();
        $clients = $orders->pluck('user.name')->unique()->values()->all();

        $totals = [];
        $totalBuyersPerMonth = [];

        foreach ($months as $month) {
            $salesByClient = [];
            $totalMonth = 0;
            $buyersMonth = $orders->where('month', $month)->pluck('user_id')->unique()->count();

            foreach ($clients as $client) {
                $total = $orders->where('month', $month)
                    ->where('user.name', $client)
                    ->sum('total');

                // Aplica el filtro de totales por cliente
                $clientPassesFilter = true;
                if ($totalMin !== null) {
                    $clientPassesFilter = $clientPassesFilter && $total >= $totalMin;
                }
                if ($totalMax !== null) {
                    $clientPassesFilter = $clientPassesFilter && $total <= $totalMax;
                }

                // Solo incluye el cliente si pasa el filtro
                if ($clientPassesFilter) {
                    $salesByClient[] = [
                        'customer' => $client,
                        'total' => $total
                    ];
                    $totalMonth += $total;
                }
            }

            // Solo incluye el mes si tiene clientes que pasaron el filtro
            if (count($salesByClient) > 0) {
                $totals[] = [
                    'month' => $month,
                    'sales_by_customer' => $salesByClient,
                    'total_month' => $totalMonth
                ];
                $totalBuyersPerMonth[] = [
                    'month' => $month,
                    'total_buyers' => count($salesByClient)
                ];
            }
        }

        // Actualiza los meses para que coincidan con los totales filtrados
        $months = collect($totals)->pluck('month')->all();

        // Actualiza los clientes para que solo incluya los que pasaron el filtro
        $clients = collect($totals)
            ->pluck('sales_by_customer')
            ->flatten(1)
            ->pluck('customer')
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'months' => $months,
            'customers' => $clients,
            'totals' => $totals,
            'total_buyers_per_month' => $totalBuyersPerMonth
        ]);
    }

    public function productsSalesList(Request $request)
    {
        $start = $request->input('start')
            ? date('Y-m-d', strtotime($request->input('start')))
            : now()->subMonths(12)->startOfMonth()->toDateString();

        $end = $request->input('end')
            ? date('Y-m-d', strtotime($request->input('end')))
            : now()->endOfMonth()->toDateString();

        $perPage = $request->input('per_page', 15);

        // Usa el scope con el tipo 'top-productos'
        $query = Order::searchReport($start, $end, 'top-products');
        $ordersPaginated = $query->paginate($perPage);

        // productos relacionados para el detalle
        $productIds = $ordersPaginated->pluck('product_id')->unique()->all();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        // Detalle para tabla paginada
        $detalleTabla = [];
        foreach ($ordersPaginated as $order) {
            $producto = $products->get($order->product_id);
            $detalleTabla[] = [
                'product_id' => $order->product_id,
                'product' => $producto ? $producto->name : null,
                'subtotal' => (float)$order->subtotal,
                'margen' => 0,
                'total_sales' => (int)$order->total_sales,
                'month' => $order->month,
            ];
        }

        return response()->json([
            'table_detail' => $detalleTabla,
            'pagination' => [
                'current_page' => $ordersPaginated->currentPage(),
                'last_page' => $ordersPaginated->lastPage(),
                'per_page' => $ordersPaginated->perPage(),
                'total' => $ordersPaginated->total(),
            ]
        ]);
    }

    public function transactionsList(Request $request)
    {
        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'client' => 'nullable|string|exists:users,name',
            'total_min' => 'nullable|numeric|min:0',
            'total_max' => 'nullable|numeric|gte:total_min',
            'per_page' => 'nullable|integer|min:1|max:100'
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->toDateString();
        $client = $validated['client'] ?? null;
        $totalMin = $validated['total_min'] ?? null;
        $totalMax = $validated['total_max'] ?? null;
        $perPage = $validated['per_page'] ?? 15;

        $query = \App\Models\Order::with('user')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end]);

        // Filtro por cliente
        if ($client) {
            $query->whereHas('user', function($q) use ($client) {
                $q->where('name', $client);
            });
        }

        // Filtros por monto
        if ($totalMin !== null) {
            $query->where('amount', '>=', $totalMin);
        }
        if ($totalMax !== null) {
            $query->where('amount', '<=', $totalMax);
        }

        $query->orderByDesc('created_at');
        $ordersPaginated = $query->paginate($perPage);

        $detalleTabla = [];
        foreach ($ordersPaginated as $order) {
            $detalleTabla[] = [
                'id' => $order->id,
                'customer' => $order->user ? $order->user->name : null,
                'amount' => $order->amount,
                'date' => $order->created_at ? $order->created_at->toDateString() : null,
                'status' => $order->status,
            ];
        }

        return response()->json([
            'table_detail' => $detalleTabla,
            'pagination' => [
                'current_page' => $ordersPaginated->currentPage(),
                'last_page' => $ordersPaginated->lastPage(),
                'per_page' => $ordersPaginated->perPage(),
                'total' => $ordersPaginated->total(),
            ]
        ]);
    }

    public function clientsList(Request $request)
    {
        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'client' => 'nullable|string|exists:users,name',
            'total_min' => 'nullable|numeric|min:0',
            'total_max' => 'nullable|numeric|gte:total_min',
            'per_page' => 'nullable|integer|min:1|max:100',
            'region' => 'nullable|string|exists:regions,code'
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
            'region.exists' => 'El código de región no existe en los registros.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->toDateString();
        $client = $validated['client'] ?? null;
        $totalMin = $validated['total_min'] ?? null;
        $totalMax = $validated['total_max'] ?? null;
        $perPage = $validated['per_page'] ?? 15;
        $regionCode = $validated['region'] ?? null;

        $query = \App\Models\Order::with('user')
            ->select('user_id', DB::raw('SUM(amount) as monto_total'))
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('user_id');

        // Filtro por cliente
        if ($client) {
            $query->whereHas('user', function($q) use ($client) {
                $q->where('name', $client);
            });
        }

        // Filtro por código de región
        if ($regionCode) {
            // Obtener IDs de municipios que pertenecen a la región especificada
            $municipalityIds = \App\Models\Municipality::whereHas('region', function($q) use ($regionCode) {
                $q->where('code', $regionCode);
            })->pluck('id')->toArray();

            if (!empty($municipalityIds)) {
                $query->whereIn('order_meta->address->municipality_id', $municipalityIds);
            } else {
                // Si no hay municipios para esta región, no devolver ningún resultado
                $query->whereRaw('1 = 0');
            }
        }

        // Filtros por monto total (después del GROUP BY)
        if ($totalMin !== null) {
            $query->havingRaw('SUM(amount) >= ?', [$totalMin]);
        }
        if ($totalMax !== null) {
            $query->havingRaw('SUM(amount) <= ?', [$totalMax]);
        }

        $query->orderByDesc('monto_total');
        $clientsPaginated = $query->paginate($perPage);

        $detalleTabla = [];
        foreach ($clientsPaginated as $clientData) {
            $detalleTabla[] = [
                'id' => $clientData->user_id,
                'cliente' => $clientData->user ? $clientData->user->name : null,
                'monto_total' => (float)$clientData->monto_total,
            ];
        }

        return response()->json([
            'table_detail' => $detalleTabla,
            'pagination' => [
                'current_page' => $clientsPaginated->currentPage(),
                'last_page' => $clientsPaginated->lastPage(),
                'per_page' => $clientsPaginated->perPage(),
                'total' => $clientsPaginated->total(),
            ]
        ]);
    }

    public function failedTransactionsList(Request $request)
    {
        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'client' => 'nullable|string|exists:users,name',
            'total_min' => 'nullable|numeric|min:0',
            'total_max' => 'nullable|numeric|gte:total_min',
            'per_page' => 'nullable|integer|min:1|max:100'
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->toDateString();
        $client = $validated['client'] ?? null;
        $totalMin = $validated['total_min'] ?? null;
        $totalMax = $validated['total_max'] ?? null;
        $perPage = $validated['per_page'] ?? 15;

        $query = \App\Models\Order::with('user')
            ->where('status', 'failed')
            ->whereBetween('created_at', [$start, $end]);

        // Filtro por cliente
        if ($client) {
            $query->whereHas('user', function($q) use ($client) {
                $q->where('name', $client);
            });
        }

        // Filtros por monto
        if ($totalMin !== null) {
            $query->where('amount', '>=', $totalMin);
        }
        if ($totalMax !== null) {
            $query->where('amount', '<=', $totalMax);
        }

        $query->orderByDesc('created_at');
        $ordersPaginated = $query->paginate($perPage);

        $detalleTabla = [];
        foreach ($ordersPaginated as $order) {
            $detalleTabla[] = [
                'id' => $order->id,
                'client' => $order->user ? $order->user->name : null,
                'amount' => $order->amount,
                'date' => $order->created_at ? $order->created_at->toDateString() : null,
                'status' => $order->status,
            ];
        }

        return response()->json([
            'table_detail' => $detalleTabla,
            'pagination' => [
                'current_page' => $ordersPaginated->currentPage(),
                'last_page' => $ordersPaginated->lastPage(),
                'per_page' => $ordersPaginated->perPage(),
                'total' => $ordersPaginated->total(),
            ]
        ]);
    }

    public function transactionId($id)
    {
        $order = Order::with(['user', 'orderDetails.product'])->find($id);

        if (!$order) {
            return response()->json(['message' => 'Transacción no encontrada.'], 404);
        }

        return response()->json([
            'order' => [
                'id' => $order->id,
                'user' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                ] : null,
                'status' => $order->status,
                'subtotal' => $order->subtotal,
                'amount' => $order->amount,
                'order_meta' => $order->order_meta,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                'order_items' => $order->orderDetails->map(function($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product' => $item->product ? $item->product->name : null,
                        'quantity' => (int) $item->quantity,
                        'price' => (int) $item->price,
                        'subtotal' => (int) $item->price * (int) $item->quantity,
                    ];
                }),
            ]
        ]);
    }

    public function clientsExport(Request $request)
    {
        $aggregate = $request->query('aggregate');
        $fileName = $request->input('filename');

        if ($aggregate === 'sales') {
            $fileName = $fileName ?? 'clientes_con_ventas_' . now()->format('Ymd') . '.xlsx';

            // Puedes pasar los filtros si tu export lo requiere
            $validated = $request->validate([
                'start' => 'nullable|date',
                'end' => 'nullable|date|after_or_equal:start',
                'client' => 'nullable|string|exists:users,name',
                'total_min' => 'nullable|numeric|min:0',
                'total_max' => 'nullable|numeric|gte:total_min',
            ], [
                'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
                'client.exists' => 'El cliente no existe en los registros.',
                'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
            ]);

            $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
            $end = $validated['end'] ?? now()->endOfMonth()->toDateString();
            $client = $validated['client'] ?? null;
            $totalMin = $validated['total_min'] ?? null;
            $totalMax = $validated['total_max'] ?? null;

            return Excel::download(
                new ClientsReportExport($start, $end, $client, $totalMin, $totalMax),
                $fileName
            );
        } else {
            $fileName = $fileName ?? 'clientes_' . now()->format('Ymd') . '.xlsx';
            return Excel::download(new \App\Exports\CustomersExport(), $fileName);
        }
    }


    public function export(Request $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');
        $client = $request->input('client');
        $totalMin = $request->input('total_min');
        $totalMax = $request->input('total_max');
        $status = $request->input('status', 'completed');
        $fileName = $request->input('filename') ?? 'Lista_transacciones_' . now()->format('Ymd') . '.xlsx';

        return Excel::download(new OrdersExport($start, $end, $client, $totalMin, $totalMax, $status), $fileName);
    }

    public function exportTopMunicipalities(Request $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');
        $totalMin = $request->input('total_min');
        $totalMax = $request->input('total_max');
        $fileName = $request->input('filename') ?? 'Top_comunas_ventas_' . now()->format('Ymd') . '.xlsx';

        return Excel::download(new TopMunicipalitiesExport($start, $end, $totalMin, $totalMax), $fileName);
    }

    public function exportTopProducts(Request $request)
    {
        $aggregate = $request->query('aggregate');
        $start = $request->input('start');
        $end = $request->input('end');
        $totalMin = $request->input('total_min');
        $totalMax = $request->input('total_max');
        $fileName = $request->input('filename') ?? 'Top_productos_ventas_' . now()->format('Ymd') . '.xlsx';

        if ($aggregate === 'sales') {
            // Exportar productos con ventas (ya lo hace tu export actual)
            return Excel::download(new TopProductsExport($start, $end, $totalMin, $totalMax), $fileName);
        } else {
            $fileName = $request->input('filename') ?? 'productos_' . now()->format('Ymd') . '.xlsx';
            return Excel::download(new \App\Exports\ProductsExport(), $fileName);
        }
    }

    public function exportTopCategories(Request $request)
    {
        $aggregate = $request->query('aggregate');
        $start = $request->input('start');
        $end = $request->input('end');
        $totalMin = $request->input('total_min');
        $totalMax = $request->input('total_max');

        if ($aggregate === 'sales') {
            $fileName = $request->input('filename') ?? 'Top_categorias_ventas_' . now()->format('Ymd') . '.xlsx';
            return Excel::download(new TopCategoriesExport($start, $end, $totalMin, $totalMax), $fileName);
        } else {
            $fileName = $request->input('filename') ?? 'categorias_' . now()->format('Ymd') . '.xlsx';
            return Excel::download(new CategoriesExport(), $fileName);
        }
    }

    public function ordersReportExport(Request $request)
    {
        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'client' => 'nullable|string|exists:users,name',
            'type' => 'nullable|string',
            'total_min' => 'nullable|numeric|min:0',
            'total_max' => 'nullable|numeric|gte:total_min',
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->toDateString();
        $client = $validated['client'] ?? null;
        $totalMin = $validated['total_min'] ?? null;
        $totalMax = $validated['total_max'] ?? null;
        $type = $validated['type'] ?? 'sales';
        $fileName = 'Reporte_ordenes_' . now()->format('Ymd') . '.xlsx';

        return Excel::download(
            new OrdersReportExport($start, $end, $client, $totalMin, $totalMax, $type),
            $fileName
        );
    }
}
