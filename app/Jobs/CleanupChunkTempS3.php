<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CleanupChunkTempS3 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $files;
    public $tmpPrefix;

    public function __construct(array $files, string $tmpPrefix)
    {
        $this->files = $files;
        $this->tmpPrefix = rtrim($tmpPrefix, '/') . '/images/';
    }

    public function handle()
    {
        foreach ($this->files as $file) {
            $path = $this->tmpPrefix . $file;
            if (Storage::disk('s3')->exists($path)) {
                Storage::disk('s3')->delete($path);
            }
        }
        Log::info('CleanupChunkTempS3 completed', ['prefix' => $this->tmpPrefix]);
    }
}