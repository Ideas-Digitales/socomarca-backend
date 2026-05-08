<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductImagePresignedUrlController extends Controller
{
    public function store()
    {
        $fileName = uniqid() . '.zip';
        $path = "product-sync/{$fileName}";
        $result = Storage::disk('s3')->temporaryUploadUrl(
            "product-sync/{$fileName}",
            now()->addMinutes(5),
        );

        $response = [
            'data' => [
                'presigned_upload_url' => $result['url'],
                'host' => $result['headers']['Host'],
                'path' => $path,
            ]
        ];

        return response()->json($response);
    }
}
