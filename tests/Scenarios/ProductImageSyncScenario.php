<?php

namespace Tests\Scenarios;

use App\Models\User;
use ZipArchive;

class ProductImageSyncScenario
{
    public function __construct(
        public User $user,
    ) {}

    public static function make(): ProductImageSyncScenario
    {
        $user = createUserWithPermissions(['sync-product-images']);

        return new ProductImageSyncScenario($user);
    }

    /**
     * Create a real ZIP in memory with an images/ folder containing dummy image files.
     * Returns the path to the temp ZIP file.
     */
    public static function createTestZipWithImages(array $imageNames): string
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
}
