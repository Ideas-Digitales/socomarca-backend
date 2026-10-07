<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

#[Group('Products', weight: 7)]
class ProductImagePresignedUrlController extends Controller
{
    /**
     * Create a product images upload URL
     *
     * First step of the product image sync: returns a presigned S3 URL, valid for 5 minutes, where the
     * client uploads with `PUT` a ZIP of product images. The ZIP must contain an `images/` folder with
     * one image per product named after its SKU (e.g. `images/SKU-12345.jpg`; jpg, jpeg, png, gif, webp,
     * bmp or svg). Then send the returned `path` to Sync product images.
     */
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
                /** Presigned URL to upload the ZIP with `PUT`. */
                'presigned_upload_url' => $result['url'],
                /** `Host` header to send with the upload. */
                'host' => $result['headers']['Host'],
                /**
                 * Storage path of the ZIP, to send as `sync_file_path` to Sync product images.
                 *
                 * @example product-sync/6703f1a2b4c5d.zip
                 */
                'path' => $path,
            ]
        ];

        return response()->json($response);
    }
}
