<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImages\ProductImageSyncStoreRequest;
use App\Jobs\SyncProductImage;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Products', weight: 7)]
class ProductImageSyncController extends Controller
{
    /**
     * Sync product images
     *
     * Queues the processing of a ZIP uploaded with Create a product images upload URL and responds
     * right away. Each image is assigned to the product with the SKU of its file name, replacing its
     * current image; images without a matching product are skipped. The ZIP is deleted afterwards.
     */
    public function store(ProductImageSyncStoreRequest $request): JsonResponse
    {
        SyncProductImage::dispatch($request->input('sync_file_path'));
        return response()->json(['message' => 'Sincronización iniciada.']);
    }
}
