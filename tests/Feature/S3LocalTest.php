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

    it('admin can generate presigned URL for ZIP upload', function () {
        Storage::shouldReceive('disk')
            ->with('s3')
            ->andReturn(
                \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class, function ($mock) {
                    $mock->shouldReceive('temporaryUploadUrl')->andReturn([
                        'url' => 'https://s3-fake-url.amazonaws.com/product-sync/file.zip',
                        'headers' => [
                            'Host' => 's3-fake-url.amazonaws.com',
                        ]
                    ]);
                })
            );

        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $response = $this->actingAs($user, 'sanctum')
            ->post(route('products.image.presigned-upload-url'), [], [
                'Accept' => 'application/json'
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'presigned_upload_url',
                    'host',
                    'path',
                ]
            ]);
    });

    it('admin can initiate sync providing an existing s3 file path', function () {
        Storage::fake('s3');
        Queue::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $product1 = Product::factory()->create(['sku' => '8072']);
        $product2 = Product::factory()->create(['sku' => '3150']);

        // Mock an already uploaded file on S3
        $s3Path = 'product-sync/test-file.zip';
        Storage::disk('s3')->put($s3Path, 'fake-zip-content');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file_path' => $s3Path
            ], [
                'Accept' => 'application/json'
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Sincronización iniciada.']);

        Queue::assertPushed(SyncProductImage::class, function ($job) use ($s3Path) {
            return $job->zipPath === $s3Path;
        });
    });

    it('user without permissions cannot generate presigned URL or initiate sync', function () {
        /** @var Tests\TestCase $this */

        $user = User::factory()->create();
        $user->assignRole('customer');

        Storage::shouldReceive('disk')
            ->with('s3')
            ->andReturn(
                \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class, function ($mock) {
                    $mock->shouldReceive('temporaryUploadUrl')->andReturn([
                        'url' => 'https://s3-fake-url.amazonaws.com/product-sync/file.zip',
                        'host' => 's3-fake-url.amazonaws.com',
                    ]);
                })
            );

        // Test presigned URL endpoint
        $response1 = $this->actingAs($user, 'sanctum')
            ->post(route('products.image.presigned-upload-url'), [], [
                'Accept' => 'application/json'
            ]);
        $response1->assertStatus(403);

        // Test sync endpoint
        $response2 = $this->actingAs($user, 'sanctum')
            ->post(route('products.image.sync'), [
                'sync_file_path' => 'dummy-path.zip'
            ], [
                'Accept' => 'application/json'
            ]);
        $response2->assertStatus(403);
    });

    it('cannot initiate sync if file does not exist on s3', function () {
        Storage::fake('s3');
        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/products/images/sync', [
                'sync_file_path' => 'product-sync/non-existent.zip'
            ], [
                'Accept' => 'application/json'
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sync_file_path']);
    });

    it('sync_file_path field is required', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('sync-product-images');

        $response = $this->actingAs($user, 'sanctum')
            ->post(
                '/api/products/images/sync',
                [],
                ['Accept' => 'application/json']
            );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sync_file_path']);
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
