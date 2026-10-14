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
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

#[Group('Reports', 'Sales dashboards over orders (charts, top customers, products, categories and municipalities, transactions) and Excel exports.', weight: 17)]
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

    /**
     * Get the dashboard report
     *
     * Returns the month-by-month chart chosen with `type`; the response shape depends on it. Months are
     * `YYYY-MM` strings.
     *
     * Only `transactions`, `transactions-failed` and `top-municipalities` filter by order status (completed
     * or failed orders); the other types count orders in any status. `client` is ignored by
     * `top-municipalities`, and `total_min`/`total_max` are ignored by `top-customers`, `transactions` and
     * `revenue`.
     */
    public function report(Request $request)
    {
        // Validación de filtros
        $validated = $request->validate([
            /**
             * Start of the range, compared with the order creation date. Defaults to the first day of the
             * month 12 months ago.
             *
             * @example 2025-01-01
             */
            'start' => 'nullable|date',
            /**
             * End of the range, inclusive. A date without time means the start of that day, so send the time
             * to include the whole day. Defaults to the end of the current month.
             *
             * @example 2025-06-30 23:59:59
             */
            'end' => 'nullable|date|after_or_equal:start',
            /** Exact name of the user who placed the orders. */
            'client' => 'nullable|string|exists:users,name',
            /**
             * Chart to return: `sales` (sales per customer), `revenue` (sum of order subtotals), `transactions`
             * (completed orders), `transactions-failed` (failed orders), or the top one per month of
             * `top-customers` (by amount), `top-products` and `top-categories` (by units sold) and
             * `top-municipalities` (by amount of completed orders). Other values behave as `sales`.
             *
             * @default sales
             */
            'type' => 'nullable|string',
            /**
             * Minimum amount. Compared with the customer's monthly total (`sales`), the product's or category's
             * monthly sales before picking the top one (`top-products`, `top-categories`), the monthly total
             * (`transactions-failed`) or the top municipality's monthly total (`top-municipalities`).
             */
            'total_min' => 'nullable|numeric|min:0',
            /** Maximum amount, compared like `total_min`. */
            'total_max' => 'nullable|numeric|gte:total_min',
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->endOfDay()->toDateTimeString();
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
                /**
                 * Municipality of the shipping address with the highest amount of completed orders, per month.
                 * `quantity` is its number of orders.
                 *
                 * @var list<array{month: string, municipality: string, total_purchases: int, quantity: int}>
                 */
                'top_municipalities' => $topMunicipalities->values(),
                /**
                 * Sum of `total_purchases` of the listed months.
                 *
                 * @var int
                 */
                'total_purchases' => $total_purchases,
                /**
                 * Sum of `quantity` of the listed months.
                 *
                 * @var int
                 */
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
                        /** Name of the user who placed the orders. */
                        'customer' => $user ? $user->name : null,
                        'total_purchases' => (int)$top->total_purchases,
                        /** Number of orders. */
                        'quantity_purchases' => (int)$top->quantity_purchases,
                    ];
                    $totalSales += $top->total_purchases;
                }
            }

            return response()->json([
                'top_customers' => $topClients,
                /**
                 * Sum of `total_purchases` of the listed months.
                 *
                 * @var float
                 */
                'total_sales' => $totalSales
            ]);
        }

        if ($type === 'transactions') {
            $chart = [];
            foreach ($orders as $order) {
                $chart[] = [
                    'month' => $order->month,
                    /** Number of completed orders. */
                    'transactions' => (int)$order->transactions,
                    /** Sum of their amounts. */
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
                    /** Number of failed orders. */
                    'failed_transactions' => (int)$order->transactions_failed,
                    /** Sum of their amounts. */
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
                        /** Name of the product with the most units sold. */
                        'product' => $product ? $product->name : null,
                        /** Sales of the product (price × quantity). */
                        'total' => (int)$top->subtotal,
                    ];
                    $total_sales += (int)$top->subtotal;
                }
            }

            return response()->json([
                'top_products' => $topProducts,
                /** Sum of `total` of the listed months. */
                'total_sales' => $total_sales,
            ]);
        }

        if ($type === 'revenue') {
            $revenues = [];
            $total_revenue = 0;
            foreach ($orders as $order) {
                $revenues[] = [
                    'month' => $order->month,
                    /** Sum of the order subtotals. */
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
                        /** Name of the category with the most units sold. */
                        'category' => $top->category,
                        /** Sales of the category (price × quantity). */
                        'total' => (int)$top->subtotal,
                    ];
                }
            }

            $totalSales = collect($topCategories)->sum('total');
            $averageSales = count($topCategories) > 0 ? round($totalSales / count($topCategories), 0) : 0;

            return response()->json([
                'top_categories' => $topCategories,
                /**
                 * Sum of `total` of the listed months.
                 *
                 * @var int
                 */
                'total_sales' => $totalSales,
                /** Average of `total` per listed month, rounded. */
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
                        /** @var string */
                        'customer' => $client,
                        /** @var float */
                        'total' => $total
                    ];
                    $totalMonth += $total;
                }
            }

            // Solo incluye el mes si tiene clientes que pasaron el filtro
            if (count($salesByClient) > 0) {
                $totals[] = [
                    'month' => $month,
                    /**
                     * Sum of order amounts per user who placed orders. Every customer of the range is listed in
                     * every month, with `0` when they did not buy that month, unless excluded by
                     * `total_min`/`total_max`.
                     */
                    'sales_by_customer' => $salesByClient,
                    /** @var float */
                    'total_month' => $totalMonth
                ];
                $totalBuyersPerMonth[] = [
                    'month' => $month,
                    /** Number of customers listed in `sales_by_customer` for the month. */
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
            /** @var list<string> */
            'months' => $months,
            /**
             * Names of the customers listed in `totals`.
             *
             * @var list<string>
             */
            'customers' => $clients,
            /** Months with at least one listed customer. */
            'totals' => $totals,
            'total_buyers_per_month' => $totalBuyersPerMonth
        ]);
    }

    /**
     * List product sales by month
     *
     * Paginated units sold and sales per product and month, ordered by month and then by units sold. Counts
     * orders in any status.
     */
    #[BodyParameter('start', 'Start date of the range. Defaults to the first day of the month 12 months ago.', type: 'string', format: 'date', example: '2025-01-01')]
    #[BodyParameter('end', 'End date of the range. The time is discarded and the range ends at the start of that day, so orders of that day are not counted. Defaults to the end of the current month.', type: 'string', format: 'date', example: '2025-07-01')]
    #[BodyParameter('per_page', 'Items per page.', type: 'int', default: 15)]
    #[BodyParameter('page', 'Page number.', type: 'int', default: 1)]
    public function productsSalesList(Request $request)
    {
        $start = $request->input('start')
            ? date('Y-m-d', strtotime($request->input('start')))
            : now()->subMonths(12)->startOfMonth()->toDateString();

        $end = $request->input('end')
            ? date('Y-m-d', strtotime($request->input('end')))
            : now()->endOfMonth()->endOfDay()->toDateTimeString();

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
                /** @var int */
                'product_id' => $order->product_id,
                'product' => $producto ? $producto->name : null,
                /** Sales of the product in the month (price × quantity). */
                'subtotal' => (float)$order->subtotal,
                /** Not calculated, always `0`. */
                'margen' => 0,
                /** Units sold in the month. */
                'total_sales' => (int)$order->total_sales,
                /** Month, as `YYYY-MM`. */
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

    /**
     * List completed transactions
     *
     * Paginated completed orders created in the range, newest first.
     */
    #[BodyParameter('page', 'Page number.', type: 'int', default: 1)]
    public function transactionsList(Request $request)
    {
        $validated = $request->validate([
            /**
             * Start of the range, compared with the order creation date. Defaults to the first day of the
             * month 12 months ago.
             *
             * @example 2025-01-01
             */
            'start' => 'nullable|date',
            /**
             * End of the range, inclusive. A date without time means the start of that day, so send the time
             * to include the whole day. Defaults to the end of the current month.
             *
             * @example 2025-06-30 23:59:59
             */
            'end' => 'nullable|date|after_or_equal:start',
            /** Exact name of the user who placed the orders. */
            'client' => 'nullable|string|exists:users,name',
            /** Minimum order amount. */
            'total_min' => 'nullable|numeric|min:0',
            /** Maximum order amount. */
            'total_max' => 'nullable|numeric|gte:total_min',
            /** @default 15 */
            'per_page' => 'nullable|integer|min:1|max:100'
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->endOfDay()->toDateTimeString();
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
                /**
                 * Order ID.
                 *
                 * @var int
                 */
                'id' => $order->id,
                /** Name of the user who placed the order. */
                'customer' => $order->user ? $order->user->name : null,
                /** @var float */
                'amount' => $order->amount,
                /** Creation date, as `YYYY-MM-DD`. */
                'date' => $order->created_at ? $order->created_at->toDateString() : null,
                /** @var 'completed' */
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

    /**
     * List customers by purchases
     *
     * Paginated users who placed completed orders in the range, with the sum of their order amounts,
     * highest first.
     */
    #[BodyParameter('page', 'Page number.', type: 'int', default: 1)]
    public function clientsList(Request $request)
    {
        $validated = $request->validate([
            /**
             * Start of the range, compared with the order creation date. Defaults to the first day of the
             * month 12 months ago.
             *
             * @example 2025-01-01
             */
            'start' => 'nullable|date',
            /**
             * End of the range, inclusive. A date without time means the start of that day, so send the time
             * to include the whole day. Defaults to the end of the current month.
             *
             * @example 2025-06-30 23:59:59
             */
            'end' => 'nullable|date|after_or_equal:start',
            /** Exact name of the user who placed the orders. */
            'client' => 'nullable|string|exists:users,name',
            /** Minimum sum of the customer's order amounts. */
            'total_min' => 'nullable|numeric|min:0',
            /** Maximum sum of the customer's order amounts. */
            'total_max' => 'nullable|numeric|gte:total_min',
            /** @default 15 */
            'per_page' => 'nullable|integer|min:1|max:100',
            /** Region code. Only counts orders shipped to a municipality of the region. */
            'region' => 'nullable|string|exists:regions,code'
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
            'region.exists' => 'El código de región no existe en los registros.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->endOfDay()->toDateTimeString();
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
                /**
                 * User ID.
                 *
                 * @var int
                 */
                'id' => $clientData->user_id,
                /** User name. */
                'cliente' => $clientData->user ? $clientData->user->name : null,
                /** Sum of the order amounts. */
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

    /**
     * List failed transactions
     *
     * Paginated failed orders created in the range, newest first.
     */
    #[BodyParameter('page', 'Page number.', type: 'int', default: 1)]
    public function failedTransactionsList(Request $request)
    {
        $validated = $request->validate([
            /**
             * Start of the range, compared with the order creation date. Defaults to the first day of the
             * month 12 months ago.
             *
             * @example 2025-01-01
             */
            'start' => 'nullable|date',
            /**
             * End of the range, inclusive. A date without time means the start of that day, so send the time
             * to include the whole day. Defaults to the end of the current month.
             *
             * @example 2025-06-30 23:59:59
             */
            'end' => 'nullable|date|after_or_equal:start',
            /** Exact name of the user who placed the orders. */
            'client' => 'nullable|string|exists:users,name',
            /** Minimum order amount. */
            'total_min' => 'nullable|numeric|min:0',
            /** Maximum order amount. */
            'total_max' => 'nullable|numeric|gte:total_min',
            /** @default 15 */
            'per_page' => 'nullable|integer|min:1|max:100'
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->endOfDay()->toDateTimeString();
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
                /**
                 * Order ID.
                 *
                 * @var int
                 */
                'id' => $order->id,
                /** Name of the user who placed the order. */
                'client' => $order->user ? $order->user->name : null,
                /** @var float */
                'amount' => $order->amount,
                /** Creation date, as `YYYY-MM-DD`. */
                'date' => $order->created_at ? $order->created_at->toDateString() : null,
                /** @var 'failed' */
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

    /**
     * Get a transaction
     *
     * Returns an order in any status, with the user who placed it and its items.
     *
     * @param int $id Order ID.
     */
    public function transactionId($id)
    {
        $order = Order::with(['user', 'orderDetails.product'])->find($id);

        if (!$order) {
            return response()->json(['message' => 'Transacción no encontrada.'], 404);
        }

        return response()->json([
            'order' => [
                /** @var int */
                'id' => $order->id,
                /** User who placed the order. */
                'user' => $order->user ? [
                    /** @var int */
                    'id' => $order->user->id,
                    /** @var string */
                    'name' => $order->user->name,
                    /** @var string */
                    'email' => $order->user->email,
                ] : null,
                /** @var 'pending'|'processing'|'on_hold'|'completed'|'canceled'|'refunded'|'failed' */
                'status' => $order->status,
                /**
                 * Net subtotal of the items.
                 *
                 * @var float
                 */
                'subtotal' => $order->subtotal,
                /**
                 * Amount charged, including VAT and shipping cost.
                 *
                 * @var float
                 */
                'amount' => $order->amount,
                /**
                 * Snapshot taken when the order was placed: `user` (the user who placed it) and `address` (the
                 * shipping address, or `null`).
                 *
                 * @var array<string, mixed>|null
                 */
                'order_meta' => $order->order_meta,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                /** @var list<array{id: int, product_id: int, product: string|null, quantity: int, price: int, subtotal: int}> */
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

    /**
     * Export customers
     *
     * With `aggregate=sales`, exports the users who placed completed orders in the range with the sum of their
     * order amounts, highest first (columns: ID, Cliente, Monto Total, Fecha of the last purchase); the body
     * fields other than `filename` only apply in this case. Otherwise exports every customer with their
     * billing address.
     */
    #[QueryParameter('aggregate', '`sales` to export customers with their sales in the range.', type: 'string', example: 'sales')]
    #[BodyParameter('filename', 'Download file name; its extension sets the format (e.g. `.xlsx`, `.csv`). Defaults to `clientes_con_ventas_<YYYYMMDD>.xlsx` with `aggregate=sales` and to `clientes_<YYYYMMDD>.xlsx` otherwise.', type: 'string', example: 'clientes.xlsx')]
    #[Response(200, 'Excel file', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function clientsExport(Request $request)
    {
        $aggregate = $request->query('aggregate');
        $fileName = $request->input('filename');

        if ($aggregate === 'sales') {
            $fileName = $fileName ?? 'clientes_con_ventas_' . now()->format('Ymd') . '.xlsx';

            // Puedes pasar los filtros si tu export lo requiere
            $validated = $request->validate([
                /**
                 * Start of the range, compared with the order creation date. Defaults to the first day of the
                 * month 12 months ago.
                 *
                 * @example 2025-01-01
                 */
                'start' => 'nullable|date',
                /**
                 * End of the range, inclusive. A date without time means the start of that day, so send the
                 * time to include the whole day. Defaults to the end of the current month.
                 *
                 * @example 2025-06-30 23:59:59
                 */
                'end' => 'nullable|date|after_or_equal:start',
                /** Exact name of the user who placed the orders. */
                'client' => 'nullable|string|exists:users,name',
                /** Minimum sum of the customer's order amounts. */
                'total_min' => 'nullable|numeric|min:0',
                /** Maximum sum of the customer's order amounts. */
                'total_max' => 'nullable|numeric|gte:total_min',
            ], [
                'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
                'client.exists' => 'El cliente no existe en los registros.',
                'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
            ]);

            $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
            $end = $validated['end'] ?? now()->endOfMonth()->endOfDay()->toDateTimeString();
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


    /**
     * Export transactions
     *
     * Exports the orders with the given status created in the range, newest first (columns: ID, Cliente,
     * Monto, Fecha, Estado). The fields are not validated.
     *
     * @response \Symfony\Component\HttpFoundation\BinaryFileResponse<string, 200, array{"Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"}, null>
     */
    #[BodyParameter('start', 'Start of the range, compared with the order creation date. Defaults to the first day of the month 12 months ago.', type: 'string', format: 'date-time', example: '2025-01-01')]
    #[BodyParameter('end', 'End of the range, inclusive. A date without time means the start of that day, so send the time to include the whole day. Defaults to the end of the current month.', type: 'string', format: 'date-time', example: '2025-06-30 23:59:59')]
    #[BodyParameter('client', 'Exact name of the user who placed the orders.', type: 'string')]
    #[BodyParameter('total_min', 'Minimum order amount.', type: 'float')]
    #[BodyParameter('total_max', 'Maximum order amount.', type: 'float')]
    #[BodyParameter('status', 'Order status.', type: "'pending'|'processing'|'on_hold'|'completed'|'canceled'|'refunded'|'failed'", default: 'completed')]
    #[BodyParameter('filename', 'Download file name; its extension sets the format (e.g. `.xlsx`, `.csv`). Defaults to `Lista_transacciones_<YYYYMMDD>.xlsx`.', type: 'string', example: 'transacciones.xlsx')]
    #[Response(200, 'Excel file', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
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

    /**
     * Export top municipalities
     *
     * Exports, per month, the municipality of the shipping address with the highest amount of completed
     * orders created in the range (columns: Comuna, Mes, Total ventas, Cantidad de órdenes). The fields are
     * not validated.
     *
     * @response \Symfony\Component\HttpFoundation\BinaryFileResponse<string, 200, array{"Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"}, null>
     */
    #[BodyParameter('start', 'Start of the range, compared with the order creation date. Defaults to the first day of the month 12 months ago.', type: 'string', format: 'date-time', example: '2025-01-01')]
    #[BodyParameter('end', 'End of the range, inclusive. A date without time means the start of that day, so send the time to include the whole day. Defaults to the end of the current month.', type: 'string', format: 'date-time', example: '2025-06-30 23:59:59')]
    #[BodyParameter('total_min', "Minimum of the top municipality's monthly total; months below it are left out.", type: 'float')]
    #[BodyParameter('total_max', "Maximum of the top municipality's monthly total; months above it are left out.", type: 'float')]
    #[BodyParameter('filename', 'Download file name; its extension sets the format (e.g. `.xlsx`, `.csv`). Defaults to `Top_comunas_ventas_<YYYYMMDD>.xlsx`.', type: 'string', example: 'comunas.xlsx')]
    #[Response(200, 'Excel file', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function exportTopMunicipalities(Request $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');
        $totalMin = $request->input('total_min');
        $totalMax = $request->input('total_max');
        $fileName = $request->input('filename') ?? 'Top_comunas_ventas_' . now()->format('Ymd') . '.xlsx';

        return Excel::download(new TopMunicipalitiesExport($start, $end, $totalMin, $totalMax), $fileName);
    }

    /**
     * Export products
     *
     * With `aggregate=sales`, exports, per month, the product with the most units sold in completed orders
     * created in the range (columns: Producto, Mes, Cantidad vendida, Total ventas). Otherwise exports every
     * product with its first price, unit and stock, and the body fields other than `filename` are ignored.
     * The fields are not validated.
     */
    #[QueryParameter('aggregate', '`sales` to export the top-selling product per month.', type: 'string', example: 'sales')]
    #[BodyParameter('start', 'Start of the range, compared with the order creation date. Defaults to the first day of the month 12 months ago.', type: 'string', format: 'date-time', example: '2025-01-01')]
    #[BodyParameter('end', 'End of the range, inclusive. A date without time means the start of that day, so send the time to include the whole day. Defaults to the end of the current month.', type: 'string', format: 'date-time', example: '2025-06-30 23:59:59')]
    #[BodyParameter('total_min', "Minimum of the top product's monthly sales; months below it are left out.", type: 'float')]
    #[BodyParameter('total_max', "Maximum of the top product's monthly sales; months above it are left out.", type: 'float')]
    #[BodyParameter('filename', 'Download file name; its extension sets the format (e.g. `.xlsx`, `.csv`). Defaults to `Top_productos_ventas_<YYYYMMDD>.xlsx` with `aggregate=sales` and to `productos_<YYYYMMDD>.xlsx` otherwise.', type: 'string', example: 'productos.xlsx')]
    #[Response(200, 'Excel file', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
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

    /**
     * Export categories
     *
     * With `aggregate=sales`, exports every category ranked by sales, highest first (columns: Ranking,
     * Categoría, Total Ventas). The totals currently sum every order item of the category's products,
     * whatever the order date and status, so `start` and `end` have no effect. Otherwise exports every
     * category with its code and level, and the body fields other than `filename` are ignored. The fields
     * are not validated.
     */
    #[QueryParameter('aggregate', '`sales` to export the categories ranked by sales.', type: 'string', example: 'sales')]
    #[BodyParameter('start', 'Start of the range. Currently has no effect.', type: 'string', format: 'date-time', example: '2025-01-01')]
    #[BodyParameter('end', 'End of the range. Currently has no effect.', type: 'string', format: 'date-time', example: '2025-06-30 23:59:59')]
    #[BodyParameter('total_min', "Minimum of the category's sales; categories below it are left out.", type: 'float')]
    #[BodyParameter('total_max', "Maximum of the category's sales; categories above it are left out.", type: 'float')]
    #[BodyParameter('filename', 'Download file name; its extension sets the format (e.g. `.xlsx`, `.csv`). Defaults to `Top_categorias_ventas_<YYYYMMDD>.xlsx` with `aggregate=sales` and to `categorias_<YYYYMMDD>.xlsx` otherwise.', type: 'string', example: 'categorias.xlsx')]
    #[Response(200, 'Excel file', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
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

    /**
     * Export orders
     *
     * Exports the completed orders created in the range, newest first (columns: ID, Cliente, Monto, Fecha),
     * as `Reporte_ordenes_<YYYYMMDD>.xlsx`.
     *
     * @response \Symfony\Component\HttpFoundation\BinaryFileResponse<string, 200, array{"Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"}, null>
     */
    #[Response(200, 'Excel file', mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', type: 'string', format: 'binary')]
    public function ordersReportExport(Request $request)
    {
        $validated = $request->validate([
            /**
             * Start of the range, compared with the order creation date. Defaults to the first day of the
             * month 12 months ago.
             *
             * @example 2025-01-01
             */
            'start' => 'nullable|date',
            /**
             * End of the range, inclusive. A date without time means the start of that day, so send the time
             * to include the whole day. Defaults to the end of the current month.
             *
             * @example 2025-06-30 23:59:59
             */
            'end' => 'nullable|date|after_or_equal:start',
            /** Exact name of the user who placed the orders. */
            'client' => 'nullable|string|exists:users,name',
            /** Accepted but ignored: it does not change the export. */
            'type' => 'nullable|string',
            /** Minimum order amount. */
            'total_min' => 'nullable|numeric|min:0',
            /** Maximum order amount. */
            'total_max' => 'nullable|numeric|gte:total_min',
        ], [
            'end.after_or_equal' => 'La fecha final no puede ser menor que la inicial.',
            'client.exists' => 'El cliente no existe en los registros.',
            'total_max.gte' => 'El monto máximo no puede ser menor que el mínimo.',
        ]);

        $start = $validated['start'] ?? now()->subMonths(12)->startOfMonth()->toDateString();
        $end = $validated['end'] ?? now()->endOfMonth()->endOfDay()->toDateTimeString();
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
