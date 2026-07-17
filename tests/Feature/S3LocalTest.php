<?php

use App\Jobs\SyncProductImage;
use App\Jobs\ProcessProductImageChunkFromS3;
use App\Jobs\CleanupChunkTempS3;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\ProductImageSyncScenario;

use function Pest\Laravel\postJson;

describe('Product Image Sync API', function () {

    it('allows an admin to generate a presigned URL for ZIP upload', function () {
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

        $scenario = ProductImageSyncScenario::make();
        Sanctum::actingAs($scenario->user, ['api-access']);

        $response = postJson(route('products.image.presigned-upload-url'), []);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'presigned_upload_url',
                    'host',
                    'path',
                ]
            ]);
    });

    it('allows an admin to initiate sync providing an existing s3 file path', function () {
        Storage::fake('s3');
        Queue::fake();

        $scenario = ProductImageSyncScenario::make();
        Sanctum::actingAs($scenario->user, ['api-access']);

        Product::factory()->create(['sku' => '8072']);
        Product::factory()->create(['sku' => '3150']);

        // Mock an already uploaded file on S3
        $s3Path = 'product-sync/test-file.zip';
        Storage::disk('s3')->put($s3Path, 'fake-zip-content');

        $response = postJson('/api/products/images/sync', [
            'sync_file_path' => $s3Path
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Sincronización iniciada.']);

        Queue::assertPushed(SyncProductImage::class, function ($job) use ($s3Path) {
            return $job->zipPath === $s3Path;
        });
    });

    it('forbids a user without permissions from generating a presigned URL or initiating sync', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');
        Sanctum::actingAs($user, ['api-access']);

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

        postJson(route('products.image.presigned-upload-url'), [])
            ->assertStatus(403);

        postJson(route('products.image.sync'), [
            'sync_file_path' => 'dummy-path.zip'
        ])->assertStatus(403);
    });

    it('rejects a sync when the file does not exist on s3', function () {
        Storage::fake('s3');
        $scenario = ProductImageSyncScenario::make();
        Sanctum::actingAs($scenario->user, ['api-access']);

        $response = postJson('/api/products/images/sync', [
            'sync_file_path' => 'product-sync/non-existent.zip'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sync_file_path']);
    });

    it('requires the sync_file_path field', function () {
        $scenario = ProductImageSyncScenario::make();
        Sanctum::actingAs($scenario->user, ['api-access']);

        $response = postJson('/api/products/images/sync', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sync_file_path']);
    });

    it('processes the ZIP with images deriving SKU from filenames', function () {
        Storage::fake('s3');
        Bus::fake();

        Product::factory()->create(['sku' => '8072', 'image' => null]);
        Product::factory()->create(['sku' => '3150', 'image' => null]);

        // Create a real ZIP with images named after SKUs
        $localZip = ProductImageSyncScenario::createTestZipWithImages(['8072.jpg', '3150.png']);
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

        Product::factory()->create(['sku' => 'ABC-123', 'image' => null]);

        $localZip = ProductImageSyncScenario::createTestZipWithImages(['ABC-123.webp']);
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

    it('handles a ZIP without an images directory gracefully', function () {
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

    it('handles a ZIP with an empty images directory', function () {
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

    it('uploads images to the S3 temp prefix before dispatching', function () {
        Storage::fake('s3');
        Bus::fake();

        Product::factory()->create(['sku' => 'SKU001', 'image' => null]);

        $localZip = ProductImageSyncScenario::createTestZipWithImages(['SKU001.jpg']);
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

    it('deletes the ZIP from S3 when the job fails', function () {
        Storage::fake('s3');

        $zipPath = 'product-sync/test.zip';
        Storage::disk('s3')->put($zipPath, 'fake-content');

        $job = new SyncProductImage($zipPath);

        $exception = new Exception('Simulated failure');
        $job->failed($exception);

        Storage::disk('s3')->assertMissing($zipPath);
    });
});
