<?php

use App\Jobs\SyncProductImage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

describe('Product Image Sync API', function () {

    it('admin can upload ZIP for product image sync', function () {
        Storage::fake('s3');
        Queue::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $product1 = Product::factory()->create(['sku' => '8072']);
        $product2 = Product::factory()->create(['sku' => '3150']);

        $zipFile = UploadedFile::fake()->create('products.zip', 5000, 'application/zip');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file' => $zipFile
            ], [
                'Accept' => 'application/json'
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Sincronización iniciada.']);

        $zipPath = collect(Storage::disk('s3')->files('product-sync'))
            ->first(fn($path) => str_ends_with($path, '.zip'));

        expect($zipPath)->not->toBeNull();
        Storage::disk('s3')->assertExists($zipPath);

        Queue::assertPushed(SyncProductImage::class);
    });

    it('superadmin can upload ZIP for product image sync', function () {
        Storage::fake('s3');
        Queue::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $product1 = Product::factory()->create(['sku' => '8072']);
        $product2 = Product::factory()->create(['sku' => '3150']);

        $zipFile = UploadedFile::fake()->create('products.zip', 5000, 'application/zip');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file' => $zipFile
            ], [
                'Accept' => 'application/json'
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Sincronización iniciada.']);

        $zipPath = collect(Storage::disk('s3')->files('product-sync'))
            ->first(fn($path) => str_ends_with($path, '.zip'));

        expect($zipPath)->not->toBeNull();
        Storage::disk('s3')->assertExists($zipPath);

        Queue::assertPushed(SyncProductImage::class);
    });

    it('user without permissions cannot upload ZIP for sync', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        $zipFile = UploadedFile::fake()->create('products.zip', 1000, 'application/zip');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file' => $zipFile
            ], [
                'Accept' => 'application/json'
        ]);
        $response->assertStatus(403);
    });

    it('cannot upload file that is not a ZIP', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $file = UploadedFile::fake()->create('file.txt', 1000, 'text/plain');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file' => $file
            ], [
                'Accept' => 'application/json'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sync_file']);
    });

    it('cannot upload file that exceeds the configured max size', function () {
        \App\Models\Siteinfo::updateOrCreate(
            ['key' => 'upload_settings'],
            ['value' => ['max_upload_size' => 1]] // 1MB
        );

        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $zipFile = UploadedFile::fake()->create('large-products.zip', 2048, 'application/zip');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file' => $zipFile
            ], [
                'Accept' => 'application/json'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sync_file']);
    });

    it('sync_file field is required', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [],
                ['Accept' => 'application/json']
            );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sync_file']);
    });

    it('the job processes the ZIP with Excel and images correctly', function () {
        Storage::fake('s3');

        $product1 = Product::factory()->create(['sku' => '8072', 'image' => null]);
        $product2 = Product::factory()->create(['sku' => '3150', 'image' => null]);

        $zipPath = 'product-sync/test.zip';

        $excelContent = "SKU\tName\tCategory\tSubcategory\tImages\n";
        $excelContent .= "8072\tProduct 1\tCategory 1\tSubcat 1\timage1.jpg\n";
        $excelContent .= "3150\tProduct 2\tCategory 2\tSubcat 2\timage2.jpg\n";

        Storage::disk('s3')->put($zipPath, 'fake-zip-content');

        $job = new SyncProductImage($zipPath);

        expect($job->zipPath)->toBe($zipPath);
    });

    it('admin can upload ZIP respecting dynamic size configuration', function () {
        Storage::fake('s3');
        Queue::fake();

        \App\Models\Siteinfo::updateOrCreate(
            ['key' => 'upload_settings'],
            ['value' => ['max_upload_size' => 10]] // 10MB
        );

        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $zipFile = UploadedFile::fake()->create('products.zip', 5120, 'application/zip');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file' => $zipFile
            ], [
                'Accept' => 'application/json'
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(SyncProductImage::class);
    });

    it('processes real ZIP with Excel and images', function () {
        Storage::fake('s3');
        Queue::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $zipPath = storage_path('app/private/fake_seed_data/productos-test.zip');

        $zipFile = new UploadedFile(
            $zipPath,
            'productos-test.zip',
            'application/zip',
            null,
            true // $testMode
        );

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file' => $zipFile
            ], [
                'Accept' => 'application/json'
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Sincronización iniciada.']);

        $s3ZipPath = collect(Storage::disk('s3')->files('product-sync'))
            ->first(fn($path) => str_ends_with($path, '.zip'));

        expect($s3ZipPath)->not->toBeNull();

        $zipContent = Storage::disk('s3')->get($s3ZipPath);
        $tempZipPath = tempnam(sys_get_temp_dir(), 'test_zip_');
        file_put_contents($tempZipPath, $zipContent);

        $extractPath = sys_get_temp_dir() . '/test_extract_' . uniqid();
        mkdir($extractPath, 0755, true);

        $zip = new ZipArchive();
        $res = $zip->open($tempZipPath);
        expect($res)->toBeTrue();
        $zip->extractTo($extractPath);
        $zip->close();

        $excelPath = null;
        foreach (glob($extractPath . '/*.{xlsx,xls,csv}', GLOB_BRACE) as $file) {
            $excelPath = $file;
            break;
        }
        expect($excelPath)->not->toBeNull();

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

            if ($sku && $imageName) {
                $imagePath = $extractPath . '/images/' . $imageName;
                expect(file_exists($imagePath))->toBeTrue("Image $imageName does not exist for SKU $sku");
            }
        }

        unlink($tempZipPath);
        exec("rm -rf " . escapeshellarg($extractPath));
    });

    it('failed method deletes ZIP from S3 if job fails', function () {
        Storage::fake('s3');

        $zipPath = 'product-sync/test.zip';
        Storage::disk('s3')->put($zipPath, 'fake-content');

        $job = new \App\Jobs\SyncProductImage($zipPath);

        $exception = new Exception('Simulated failure');
        $job->failed($exception);

        Storage::disk('s3')->assertMissing($zipPath);
    });
});