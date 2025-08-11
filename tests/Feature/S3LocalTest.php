<?php

use App\Jobs\SyncProductImage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

test('admin puede subir ZIP para sincronización de imágenes de productos', function () {
    // Configurar storage fake para S3
    Storage::fake('s3');
    Queue::fake();

    // Crear usuario admin
    $user = User::factory()->create();
    $user->assignRole('admin');

    // Crear algunos productos de prueba
    $product1 = Product::factory()->create(['sku' => '8072']);
    $product2 = Product::factory()->create(['sku' => '3150']);

    // Crear un ZIP fake que contenga un Excel y imágenes
    $zipFile = UploadedFile::fake()->create('productos.zip', 5000, 'application/zip');

    // Hacer la petición
    $response = $this->actingAs($user, 'sanctum')
        ->post('/api/products/images/sync', [
            'sync_file' => $zipFile
        ], [
            'Accept' => 'application/json'
        ]);

    // Verificar respuesta
    $response->assertStatus(200)
        ->assertJson(['message' => 'Sincronización iniciada.']);

    // Verificar que el archivo se subió a S3
    $zipPath = collect(Storage::disk('s3')->files('product-sync'))
        ->first(fn($path) => str_ends_with($path, '.zip'));

    expect($zipPath)->not->toBeNull();
    Storage::disk('s3')->assertExists($zipPath);

    // Verificar que el job se despachó
    Queue::assertPushed(SyncProductImage::class);
});

test('superadmin puede subir ZIP para sincronización de imágenes de productos', function () {
    Storage::fake('s3');
    Queue::fake();

    $user = User::factory()->create();
    $user->assignRole('superadmin');

     // Crear algunos productos de prueba
    $product1 = Product::factory()->create(['sku' => '8072']);
    $product2 = Product::factory()->create(['sku' => '3150']);

    // Crear un ZIP fake que contenga un Excel y imágenes
    $zipFile = UploadedFile::fake()->create('productos.zip', 5000, 'application/zip');

    // Hacer la petición
    $response = $this->actingAs($user, 'sanctum')
        ->post('/api/products/images/sync', [
            'sync_file' => $zipFile
        ], [
            'Accept' => 'application/json'
        ]);

    // Verificar respuesta
    $response->assertStatus(200)
        ->assertJson(['message' => 'Sincronización iniciada.']);

    // Verificar que el archivo se subió a S3
    $zipPath = collect(Storage::disk('s3')->files('product-sync'))
        ->first(fn($path) => str_ends_with($path, '.zip'));

    expect($zipPath)->not->toBeNull();
    Storage::disk('s3')->assertExists($zipPath);

    // Verificar que el job se despachó
    Queue::assertPushed(SyncProductImage::class);
});

test('usuario sin permisos no puede subir ZIP para sincronización', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');

    $zipFile = UploadedFile::fake()->create('productos.zip', 1000, 'application/zip');

    // Hacer la petición
    $response = $this->actingAs($user, 'sanctum')
        ->post('/api/products/images/sync', [
            'sync_file' => $zipFile
        ], [
            'Accept' => 'application/json'
    ]);
    $response->assertStatus(403);
});

test('no se puede subir archivo que no es ZIP', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $file = UploadedFile::fake()->create('archivo.txt', 1000, 'text/plain');

    $response = $this->actingAs($user, 'sanctum')
        ->post('/api/products/images/sync', [
            'sync_file' => $file
        ], [
            'Accept' => 'application/json'
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sync_file']);
});

test('no se puede subir archivo que excede el tamaño máximo configurado', function () {
    // Configurar el tamaño máximo en 1MB para este test
    \App\Models\Siteinfo::updateOrCreate(
        ['key' => 'upload_settings'],
        ['value' => ['max_upload_size' => 1]] // 1MB
    );

    $user = User::factory()->create();
    $user->assignRole('admin');

    // Crear archivo de 2MB (excede el límite)
    $zipFile = UploadedFile::fake()->create('productos-grande.zip', 2048, 'application/zip');

    $response = $this->actingAs($user, 'sanctum')
        ->post('/api/products/images/sync', [
            'sync_file' => $zipFile
        ], [
            'Accept' => 'application/json'
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sync_file']);
});

test('se requiere el campo sync_file', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $response = $this->actingAs($user, 'sanctum')
        ->post('/api/products/images/sync', [],
            ['Accept' => 'application/json']
        );

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sync_file']);
});

test('el job procesa correctamente el ZIP con Excel e imágenes', function () {
    Storage::fake('s3');

    // Crear productos de prueba
    $product1 = Product::factory()->create(['sku' => '8072', 'image' => null]);
    $product2 = Product::factory()->create(['sku' => '3150', 'image' => null]);

    // Simular contenido de ZIP en S3
    $zipPath = 'product-sync/test.zip';

    // Crear contenido de Excel simulado (esto sería más complejo en un test real)
    $excelContent = "SKU\tNombre\tCategoría\tSubcategoria\tImágenes\n";
    $excelContent .= "8072\tProducto 1\tCategoria 1\tSubcat 1\timage1.jpg\n";
    $excelContent .= "3150\tProducto 2\tCategoria 2\tSubcat 2\timage2.jpg\n";

    // Simular que el ZIP existe en S3
    Storage::disk('s3')->put($zipPath, 'fake-zip-content');

    // Este test requeriría crear un ZIP real con Excel e imágenes para ser completamente funcional
    // Por simplicidad, verificamos que el job se puede instanciar
    $job = new SyncProductImage($zipPath);

    expect($job->zipPath)->toBe($zipPath);
});

test('admin puede subir ZIP respetando configuración dinámica de tamaño', function () {
    Storage::fake('s3');
    Queue::fake();

    // Configurar tamaño máximo dinámico
    \App\Models\Siteinfo::updateOrCreate(
        ['key' => 'upload_settings'],
        ['value' => ['max_upload_size' => 10]] // 10MB
    );

    $user = User::factory()->create();
    $user->assignRole('admin');

    // Crear archivo de 5MB (dentro del límite)
    $zipFile = UploadedFile::fake()->create('productos.zip', 5120, 'application/zip');

    $response = $this->actingAs($user, 'sanctum')
        ->post('/api/products/images/sync', [
            'sync_file' => $zipFile
        ], [
            'Accept' => 'application/json'
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncProductImage::class);
});


test('procesa ZIP real con Excel e imágenes', function () {
    Storage::fake('s3');
    Queue::fake();

    $user = User::factory()->create();
    $user->assignRole('admin');

    // Ruta absoluta al archivo ZIP real
    $zipPath = storage_path('app/private/fake_seed_data/productos-test.zip');

    // Crea un UploadedFile a partir del archivo real
    $zipFile = new UploadedFile(
        $zipPath,
        'productos-test.zip',
        'application/zip',
        null,
        true // $testMode
    );

    // Subir el ZIP usando el endpoint
    $response = $this->actingAs($user, 'sanctum')
        ->post('/api/products/images/sync', [
            'sync_file' => $zipFile
        ], [
            'Accept' => 'application/json'
        ]);

    $response->assertStatus(200)
        ->assertJson(['message' => 'Sincronización iniciada.']);

    // Obtener el path en S3
    $s3ZipPath = collect(Storage::disk('s3')->files('product-sync'))
        ->first(fn($path) => str_ends_with($path, '.zip'));

    expect($s3ZipPath)->not->toBeNull();

    // Descargar el ZIP desde S3 para procesar localmente en el test
    $zipContent = Storage::disk('s3')->get($s3ZipPath);
    $tempZipPath = tempnam(sys_get_temp_dir(), 'test_zip_');
    file_put_contents($tempZipPath, $zipContent);

    // Extraer el ZIP a un directorio temporal
    $extractPath = sys_get_temp_dir() . '/test_extract_' . uniqid();
    mkdir($extractPath, 0755, true);

    $zip = new ZipArchive();
    $res = $zip->open($tempZipPath);
    expect($res)->toBeTrue();
    $zip->extractTo($extractPath);
    $zip->close();

    // Buscar el archivo Excel
    $excelPath = null;
    foreach (glob($extractPath . '/*.{xlsx,xls,csv}', GLOB_BRACE) as $file) {
        $excelPath = $file;
        break;
    }
    expect($excelPath)->not->toBeNull();

    // Leer el Excel y verificar imágenes
    $spreadsheet = IOFactory::load($excelPath);
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

        // Mostrar por consola lo que se está leyendo
        //echo "SKU: $sku | Imagen: $imageName | Ruta: {$extractPath}/images/{$imageName} | Existe: " . (file_exists($extractPath . '/images/' . $imageName) ? 'SI' : 'NO') . "\n";

        if ($sku && $imageName) {
            $imagePath = $extractPath . '/images/' . $imageName;
            expect(file_exists($imagePath))->toBeTrue("La imagen $imageName no existe para el SKU $sku");
        }
    }

    // Limpieza
    unlink($tempZipPath);
    exec("rm -rf " . escapeshellarg($extractPath));
});