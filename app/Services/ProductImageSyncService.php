<?php

namespace App\Services;

use App\Jobs\SyncProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductImageSyncService
{
    public function sync(UploadedFile $zipFile): void
    {
        // Subir ZIP directamente a S3
        $s3ZipPath = 'product-sync/' . uniqid() . '.zip';

        Storage::disk('s3')->put($s3ZipPath, file_get_contents($zipFile->getRealPath()));

        // Disparar Job con la ruta de S3
        SyncProductImage::dispatch($s3ZipPath);
    }
}