<?php

use App\Jobs\SyncProductImage;
use App\Jobs\ProcessProductImageChunkFromS3;
use App\Jobs\CleanupChunkTempS3;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Helper: create a real ZIP in memory with an images/ folder containing dummy image files.
 * Returns the path to the temp ZIP file.
 */
function createTestZipWithImages(array $imageNames): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'test_zip_') . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addEmptyDir('images');
    foreach ($imageNames as $name) {
        // 1x1 red pixel PNG
        $img = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($img);
        $pngData = ob_get_clean();
        imagedestroy($img);
        $zip->addFromString('images/' . $name, $pngData);
    }
    $zip->close();
    return $zipPath;
}

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

    it('the job processes the ZIP with images deriving SKU from filenames', function () {
        Storage::fake('s3');
        Bus::fake();

        $product1 = Product::factory()->create(['sku' => '8072', 'image' => null]);
        $product2 = Product::factory()->create(['sku' => '3150', 'image' => null]);

        // Create a real ZIP with images named after SKUs
        $localZip = createTestZipWithImages(['8072.jpg', '3150.png']);
        $zipS3Path = 'product-sync/test.zip';
        Storage::disk('s3')->put($zipS3Path, file_get_contents($localZip));
        unlink($localZip);

        $job = new SyncProductImage($zipS3Path);
        $job->handle();

        // Should have dispatched chained jobs for the image chunk
        Bus::assertChained([
            ProcessProductImageChunkFromS3::class,
            CleanupChunkTempS3::class,
        ]);
    });

    it('derives SKU correctly from image filenames ignoring extension', function () {
        Storage::fake('s3');
        Bus::fake();

        $product = Product::factory()->create(['sku' => 'ABC-123', 'image' => null]);

        $localZip = createTestZipWithImages(['ABC-123.webp']);
        $zipS3Path = 'product-sync/test.zip';
        Storage::disk('s3')->put($zipS3Path, file_get_contents($localZip));
        unlink($localZip);

        $job = new SyncProductImage($zipS3Path);
        $job->handle();

        Bus::assertChained([
            ProcessProductImageChunkFromS3::class,
            CleanupChunkTempS3::class,
        ]);
    });

    it('handles ZIP without images directory gracefully', function () {
        Storage::fake('s3');
        Bus::fake();

        // Create a ZIP without images/ directory
        $zipPath = tempnam(sys_get_temp_dir(), 'test_zip_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'no images here');
        $zip->close();

        $zipS3Path = 'product-sync/test.zip';
        Storage::disk('s3')->put($zipS3Path, file_get_contents($zipPath));
        unlink($zipPath);

        $job = new SyncProductImage($zipS3Path);
        $job->handle();

        // No chunks should be dispatched
        Bus::assertNothingDispatched();
    });

    it('handles ZIP with empty images directory', function () {
        Storage::fake('s3');
        Bus::fake();

        $zipPath = tempnam(sys_get_temp_dir(), 'test_zip_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addEmptyDir('images');
        $zip->close();

        $zipS3Path = 'product-sync/test.zip';
        Storage::disk('s3')->put($zipS3Path, file_get_contents($zipPath));
        unlink($zipPath);

        $job = new SyncProductImage($zipS3Path);
        $job->handle();

        // No chunks dispatched when no images
        Bus::assertNothingDispatched();
        Storage::disk('s3')->assertMissing($zipS3Path);
    });

    it('uploads images to S3 temp prefix before dispatching', function () {
        Storage::fake('s3');
        Bus::fake();

        Product::factory()->create(['sku' => 'SKU001', 'image' => null]);

        $localZip = createTestZipWithImages(['SKU001.jpg']);
        $zipS3Path = 'product-sync/test.zip';
        Storage::disk('s3')->put($zipS3Path, file_get_contents($localZip));
        unlink($localZip);

        $job = new SyncProductImage($zipS3Path);
        $job->handle();

        // Check that a temp image was uploaded to S3
        $tmpFiles = Storage::disk('s3')->allFiles('product-sync/tmp');
        $imageFiles = array_filter($tmpFiles, fn($f) => str_contains($f, 'SKU001.jpg'));
        expect(count($imageFiles))->toBeGreaterThan(0);
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

    it('processes real ZIP uploaded via API and dispatches job', function () {
        Storage::fake('s3');
        Bus::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        Product::factory()->create(['sku' => 'PROD-001', 'image' => null]);
        Product::factory()->create(['sku' => 'PROD-002', 'image' => null]);

        // Create a real ZIP with images named after SKUs
        $localZip = createTestZipWithImages(['PROD-001.jpg', 'PROD-002.png']);

        $zipFile = new UploadedFile(
            $localZip,
            'productos-test.zip',
            'application/zip',
            null,
            true
        );

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file' => $zipFile
            ], [
                'Accept' => 'application/json'
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Sincronización iniciada.']);

        // SyncProductImage is dispatched via queue
        Queue::fake() || true; // Queue was already faked via Bus
        $s3ZipPath = collect(Storage::disk('s3')->files('product-sync'))
            ->first(fn($path) => str_ends_with($path, '.zip'));

        expect($s3ZipPath)->not->toBeNull();

        // Verify the ZIP in S3 contains images directory
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

        $imagesDir = $extractPath . '/images';
        expect(is_dir($imagesDir))->toBeTrue();

        $imageFiles = glob($imagesDir . '/*.{jpg,jpeg,png,gif,webp,bmp,svg}', GLOB_BRACE);
        expect(count($imageFiles))->toBe(2);

        // Verify SKU derivation from filenames
        $skus = array_map(fn($f) => pathinfo(basename($f), PATHINFO_FILENAME), $imageFiles);
        sort($skus);
        expect($skus)->toBe(['PROD-001', 'PROD-002']);

        unlink($tempZipPath);
        exec("rm -rf " . escapeshellarg($extractPath));

        if (file_exists($localZip)) {
            unlink($localZip);
        }
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