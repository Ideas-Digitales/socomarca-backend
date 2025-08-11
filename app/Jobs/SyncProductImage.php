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

            $localImagePath = $imagesPath . '/' . $imageName;
            if (!file_exists($localImagePath)) {
                Log::warning("Imagen no encontrada: $localImagePath");
                continue;
            }

            // Subir imagen directamente a S3
            $s3ImagePath = 'products/' . $imageName;
            $imageContent = file_get_contents($localImagePath);

            Storage::disk('s3')->put($s3ImagePath, $imageContent);

            $url = Storage::disk('s3')->url($s3ImagePath);
            if (app()->environment('local')) {
                $url = str_replace('localstack:4566', 'localhost:4566', $url);
            }

            // Buscar y actualizar producto
            $product = \App\Models\Product::where('sku', $sku)->first();
            if ($product) {
                $product->image = $url;
                $product->save();
                $processedCount++;
                Log::info("Imagen actualizada para SKU: $sku", ['url' => $url]);
            } else {
                Log::warning("Producto no encontrado para SKU: $sku");
            }
        }

        // Limpiar archivos temporales
        $this->cleanup($tempZipPath, $extractPath);

        // Opcional: eliminar ZIP procesado de S3
        Storage::disk('s3')->delete($this->zipPath);

        Log::info('SyncProductImage job finalizado', [
            'processedImages' => $processedCount,
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
}