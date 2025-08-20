<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadImagesChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $files; // array of filenames
    public $extractPath;
    public $tmpPrefix; // e.g. product-sync/tmp/{uuid}/

    public $timeout = 300;

    public function __construct(array $files, string $extractPath, string $tmpPrefix)
    {
        $this->files = $files;
        $this->extractPath = $extractPath;
        $this->tmpPrefix = rtrim($tmpPrefix, '/') . '/';
    }

    public function handle()
    {
        foreach ($this->files as $file) {
            $local = $this->extractPath . '/' . $file;
            if (!file_exists($local)) {
                Log::warning("UploadImagesChunk: file not found", ['local' => $local]);
                continue;
            }
            $s3Path = $this->tmpPrefix . 'images/' . $file;
            try {
                Storage::disk('s3')->put($s3Path, file_get_contents($local));
                Log::info('Uploaded temp image', ['s3' => $s3Path]);
            } catch (\Throwable $e) {
                Log::error('UploadImagesChunk error', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }
    }
}