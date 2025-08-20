<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Product;

class ProcessProductImageChunkFromS3 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $rows; // array of rows: each row => ['sku'=>..., 'image'=>...]
    public $tmpPrefix; // same prefix where UploadImagesChunk saved files

    public $timeout = 300;

    public function __construct(array $rows, string $tmpPrefix)
    {
        $this->rows = $rows;
        $this->tmpPrefix = rtrim($tmpPrefix, '/') . '/images/';
    }

    public function handle()
    {
        Log::info('ProcessProductImageChunkFromS3 started', ['tmpPrefix' => $this->tmpPrefix, 'rows' => count($this->rows)]);
        foreach ($this->rows as $r) {
            $sku = $r['sku'] ?? null;
            $imageName = $r['image'] ?? null;
            if (!$sku || !$imageName) {
                Log::warning('ProcessProductImageChunkFromS3 invalid row', ['row' => $r]);
                continue;
            }

            $tmpS3Path = $this->tmpPrefix . $imageName; // tmp prefix + images/<imageName>
            if (!Storage::disk('s3')->exists($tmpS3Path)) {
                Log::warning('Temp image missing in S3', ['tmp' => $tmpS3Path]);
                continue;
            }

            $finalS3Path = 'products/' . $imageName;
            try {
                // copy from tmp to final (S3 provider supports copy)
                $contents = Storage::disk('s3')->get($tmpS3Path);
                Storage::disk('s3')->put($finalS3Path, $contents);

                // update product
                $product = Product::where('sku', $sku)->first();
                if ($product) {
                    $product->image = $finalS3Path;
                    $product->save();
                    Log::info('Product image updated', ['sku' => $sku, 'path' => $finalS3Path]);
                } else {
                    Log::warning('Product not found', ['sku' => $sku]);
                }
            } catch (\Throwable $e) {
                Log::error('ProcessProductImageChunkFromS3 error', ['error' => $e->getMessage(), 'sku' => $sku]);
            }
            Log::info('Processing row', ['sku'=>$sku, 'image'=>$imageName]);
        }
        Log::info('ProcessProductImageChunkFromS3 finished', ['tmpPrefix' => $this->tmpPrefix]);
    }
}