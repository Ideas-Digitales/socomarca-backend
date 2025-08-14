<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImages\ProductImageSyncStoreRequest;
use App\Services\ProductImageSyncService;
use Illuminate\Http\JsonResponse;

class ProductImageSyncController extends Controller
{
    public function store(ProductImageSyncStoreRequest $request, ProductImageSyncService $service): JsonResponse
    {
        // Encola el proceso de sincronización
        $service->sync($request->file('sync_file'));
        return response()->json(['message' => 'Sincronización iniciada.']);
    }
}