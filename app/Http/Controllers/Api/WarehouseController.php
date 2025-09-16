<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    /**
     * Display a listing of warehouses.
     */
    public function index()
    {
        $warehouses = Warehouse::active()
            ->byPriority()
            ->get();

        return response()->json([
            'data' => $warehouses
        ]);
    }

    /**
     * Display the specified warehouse.
     */
    public function show(Warehouse $warehouse)
    {
        $warehouse->load(['productStocks.product']);
        
        return response()->json([
            'data' => $warehouse
        ]);
    }

    /**
     * Set warehouse as default (priority = 1).
     */
    public function setDefault(Warehouse $warehouse)
    {
        try {
            DB::beginTransaction();

            // Reset all warehouses priority except the selected one
            Warehouse::where('id', '!=', $warehouse->id)->update(['priority' => 999]);
            
            // Set selected warehouse as default (priority = 1)
            $warehouse->priority = 1;
            $warehouse->is_active = true;
            $warehouse->save();

            DB::commit();

            return response()->json([
                'data' => $warehouse
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get stock summary by warehouse.
     */
    public function stockSummary()
    {
        $warehouses = Warehouse::active()
            ->with(['productStocks' => function ($query) {
                $query->selectRaw('warehouse_id, COUNT(*) as products_count, SUM(stock) as total_stock, SUM(reserved_stock) as total_reserved')
                    ->groupBy('warehouse_id');
            }])
            ->byPriority()
            ->get();

        return response()->json([
            'data' => $warehouses
        ]);
    }

    /**
     * Get product stock detail by warehouse.
     */
    public function productStock(Warehouse $warehouse, Request $request)
    {
        $query = $warehouse->productStocks()
            ->with(['product.category', 'product.brand']);

        // Filter by product if provided
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by unit if provided
        if ($request->has('unit')) {
            $query->where('unit', $request->unit);
        }

        // Only show products with stock
        if ($request->boolean('with_stock_only')) {
            $query->withStock();
        }

        // Only show products with available stock
        if ($request->boolean('available_only')) {
            $query->withAvailableStock();
        }

        $stocks = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => $stocks
        ]);
    }
}