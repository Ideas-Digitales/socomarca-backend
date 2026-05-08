<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImages\ProductImageSyncStoreRequest;
use App\Jobs\SyncProductImage;
use Illuminate\Http\JsonResponse;

class ProductImageSyncController extends Controller
{
    public function store(ProductImageSyncStoreRequest $request): JsonResponse
    {
        SyncProductImage::dispatch($request->input('sync_file_path'));
        return response()->json(['message' => 'Sincronización iniciada.']);
    }
}