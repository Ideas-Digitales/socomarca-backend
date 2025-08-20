<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncProductImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $zipPath;

    public function __construct($zipPath)
    {
        $this->zipPath = $zipPath;
    }

    public function handle()
    {
        Log::info('SyncProductImage job iniciado', ['zipPath' => $this->zipPath]);

        // Descargar ZIP desde S3 a memoria temporal
        if (!Storage::disk('s3')->exists($this->zipPath)) {
            Log::error('ZIP no encontrado en S3', ['zipPath' => $this->zipPath]);
            return;
        }

        $zipContent = Storage::disk('s3')->get($this->zipPath);

        // Crear archivo temporal local para procesar
        $tempZipPath = tempnam(sys_get_temp_dir(), 'sync_zip_');
        file_put_contents($tempZipPath, $zipContent);

        Log::info('ZIP descargado desde S3', ['tempPath' => $tempZipPath]);

        // Crear directorio temporal para extraer
        $extractPath = sys_get_temp_dir() . '/sync_extract_' . uniqid();
        mkdir($extractPath, 0755, true);

        // Extraer ZIP
        $zip = new \ZipArchive;
        if ($zip->open($tempZipPath) === true) {
            $zip->extractTo($extractPath);
            $zip->close();
            Log::info('ZIP extraído correctamente', ['extractPath' => $extractPath]);
        } else {
            Log::error('No se pudo abrir el ZIP', ['tempPath' => $tempZipPath]);
            unlink($tempZipPath);
            return;
        }

        // Buscar el archivo Excel por extensión
        $excelPath = null;
        foreach (glob($extractPath . '/*.{xlsx,xls,csv}', GLOB_BRACE) as $file) {
            $excelPath = $file;
            break;
        }
        $imagesPath = $extractPath . '/images';

        if (!$excelPath || !file_exists($excelPath)) {
            Log::error('Archivo Excel no encontrado', ['extractPath' => $extractPath]);
            $this->cleanup($tempZipPath, $extractPath);
            return;
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($excelPath);
            Log::info('Archivo Excel cargado correctamente', ['excelPath' => $excelPath]);
        } catch (\Throwable $e) {
            Log::error('Error al cargar archivo Excel', ['error' => $e->getMessage()]);
            $this->cleanup($tempZipPath, $extractPath);
            return;
        }

        $processedCount = 0;
        $sheet = $spreadsheet->getActiveSheet();

        // Construir array de filas (sku, image) y lista de archivos
        $rows = [];
        foreach ($sheet->getRowIterator(2) as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }

            $sku = $cells[0] ?? null;
            $imageName = $cells[4] ?? null;

            if (!$sku || !$imageName) {
                Log::warning("Fila inválida en archivo.xlsx: " . json_encode($cells));
                continue;
            }

            $rows[] = ['sku' => $sku, 'image' => $imageName];
        }

        // Si no hay filas, terminar
        if (empty($rows)) {
            Log::warning('No rows to process in Excel.');
        } else {
            // Prefijo temporal en S3 para este sync (por chunk)
            $tmpPrefix = 'product-sync/tmp/' . uniqid() . '/';

            // Tamaño de chunk: ajustar según recursos (puedes pasarlo por config)
            $chunkSize = config('sync.chunk_size', 50);
            $rowChunks = array_chunk($rows, $chunkSize);

            foreach ($rowChunks as $chunkIndex => $chunk) {
                $files = array_map(fn($r) => $r['image'], $chunk);

                // Upload synchronously from extractor host to tmpPrefix
                foreach ($files as $file) {
                    $local = $imagesPath . '/' . $file;
                    if (!file_exists($local)) {
                        Log::warning('File missing before upload', ['local' => $local]);
                        continue;
                    }
                    $tmpS3Path = $tmpPrefix . 'images/' . $file;
                    Storage::disk('s3')->put($tmpS3Path, file_get_contents($local));
                    Log::info('Uploaded temp image (sync)', ['s3' => $tmpS3Path]);
                }

                // Dispatch process and cleanup (workers read from S3)
                \Illuminate\Support\Facades\Bus::dispatchChain([
                    new \App\Jobs\ProcessProductImageChunkFromS3($chunk, $tmpPrefix),
                    new \App\Jobs\CleanupChunkTempS3($files, $tmpPrefix),
                ]);

                Log::info('Dispatched chunk', [
                    'index' => $chunkIndex,
                    'files_count' => count($files),
                    'tmpPrefix' => $tmpPrefix
                ]);
            }
        }

        // Limpiar archivos temporales
        $this->cleanup($tempZipPath, $extractPath);

        // Opcional: eliminar ZIP procesado de S3
        Storage::disk('s3')->delete($this->zipPath);

        Log::info('SyncProductImage job finalizado', [
            'dispatchedChunks' => isset($rowChunks) ? count($rowChunks) : 0,
            'zipPath' => $this->zipPath
        ]);
    }

    /**
     * Limpiar archivos temporales
     */
    private function cleanup($tempZipPath, $extractPath)
    {
        // Eliminar archivo ZIP temporal
        if (file_exists($tempZipPath)) {
            unlink($tempZipPath);
        }

        // Eliminar directorio de extracción
        if (is_dir($extractPath)) {
            exec("rm -rf " . escapeshellarg($extractPath));
        }

        Log::info('Archivos temporales eliminados', [
            'tempZip' => $tempZipPath,
            'extractPath' => $extractPath
        ]);
    }

    /**
     * Se ejecuta automáticamente si el Job falla.
     */
    public function failed(\Throwable $exception)
    {
        // Elimina el ZIP de S3 si existe
        if (Storage::disk('s3')->exists($this->zipPath)) {
            Storage::disk('s3')->delete($this->zipPath);
        }
        // Opcional: puedes loguear el error si lo deseas
        Log::error("SyncProductImage failed. ZIP eliminado de S3: {$this->zipPath}", [
            'exception' => $exception->getMessage(),
        ]);
    }
}