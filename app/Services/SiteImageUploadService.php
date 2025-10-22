<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SiteImageUploadService
{
    public function upload(UploadedFile $file, string $folder = 'customer-images'): string
    {
        $path = $folder . '/' . uniqid() . '.' . $file->getClientOriginalExtension();
        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()));
        return Storage::disk('s3')->url($path);
    }
}