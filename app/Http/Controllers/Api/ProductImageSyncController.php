<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImages\ProductImageSyncStoreRequest;
use App\Services\ProductImageSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProductImageSyncController extends Controller
{
    private const UPLOAD_SESSION_TTL_SECONDS = 600;

    public function createUploadSession(): JsonResponse
    {
        $session = (string) Str::uuid();

        Cache::put(
            $this->uploadSessionCacheKey($session),
            true,
            now()->addSeconds(self::UPLOAD_SESSION_TTL_SECONDS)
        );

        return response()->json([
            'upload_session_id' => $session,
            'expires_in_seconds' => self::UPLOAD_SESSION_TTL_SECONDS,
        ]);
    }

    public function store(ProductImageSyncStoreRequest $request, ProductImageSyncService $service): JsonResponse
    {
        // Encola el proceso de sincronización
        $service->sync($request->file('sync_file'));
        return response()->json(['message' => 'Sincronización iniciada.']);
    }

    public function storeUploadSession(
        ProductImageSyncStoreRequest $request,
        ProductImageSyncService $service,
        string $session
    ): JsonResponse
    {
        if (! Cache::pull($this->uploadSessionCacheKey($session))) {
            return response()->json(['message' => 'Sesión de carga inválida o expirada.'], 404);
        }

        $service->sync($request->file('sync_file'));

        return response()->json(['message' => 'Sincronización iniciada.']);
    }

    private function uploadSessionCacheKey(string $session): string
    {
        return "product-image-sync-upload:{$session}";
    }
}
