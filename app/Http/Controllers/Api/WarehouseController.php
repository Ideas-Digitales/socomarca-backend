<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Http\Requests\WarehouseIndexRequest;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    /**
     * Display a listing of warehouses.
     */
    public function index(WarehouseIndexRequest $request)
    {
        $query = Warehouse::active()->byPriority();

        if ($request->has('include')) {
            $includes = explode(',', $request->get('include'));
            $includes = array_map('trim', $includes);

            if (in_array('stock_summary', $includes)) {
                $query->withStockSummary();
            } elseif (in_array('product_stock', $includes)) {
                // Prepare filters for product stock
                $filters = array_filter([
                    'product_id' => $request->get('product_id'),
                    'unit' => $request->get('unit'),
                    'with_stock_only' => $request->boolean('with_stock_only'),
                    'available_only' => $request->boolean('available_only'),
                ]);

                $query->withProductStock($filters);
            }
        }

        // Handle pagination when product_stock is included
        if ($request->has('include') && str_contains($request->get('include'), 'product_stock')) {
            $warehouses = $query->paginate($request->get('per_page', 15));
            return response()->json($warehouses);
        } else {
            $warehouses = $query->get();
            return response()->json([
                'data' => $warehouses
            ]);
        }
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
     * Update the specified warehouse.
     */
    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse)
    {
        $warehouse->update($request->validated());

        return response()->json([
            'data' => $warehouse
        ]);
    }


    /**
     * Get product stock detail by warehouse.
     *
     * @deprecated Use index() with include=product_stock instead
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