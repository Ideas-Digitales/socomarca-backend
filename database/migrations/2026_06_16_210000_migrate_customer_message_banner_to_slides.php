<?php

use App\Models\Siteinfo;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $record = Siteinfo::where('key', 'customer_message')->first();

        if (!$record || !$record->value) {
            return;
        }

        $value = $record->value;
        $banner = $value['banner'] ?? [];

        if (isset($banner['slides']) && is_array($banner['slides'])) {
            return;
        }

        $slides = [];

        if (!empty($banner['desktop_image']) || !empty($banner['mobile_image'])) {
            $slides[] = [
                'id' => 'legacy-banner',
                'desktop_image' => $banner['desktop_image'] ?? '',
                'mobile_image' => $banner['mobile_image'] ?? '',
                'alt' => 'Banner principal',
                'order' => 1,
                'enabled' => true,
            ];
        }

        $value['banner'] = [
            'enabled' => (bool)($banner['enabled'] ?? false),
            'slides' => $slides,
        ];

        $record->update(['value' => $value]);
    }

    public function down(): void
    {
        $record = Siteinfo::where('key', 'customer_message')->first();

        if (!$record || !$record->value) {
            return;
        }

        $value = $record->value;
        $banner = $value['banner'] ?? [];
        $firstSlide = $banner['slides'][0] ?? [];

        $value['banner'] = [
            'desktop_image' => $firstSlide['desktop_image'] ?? '',
            'mobile_image' => $firstSlide['mobile_image'] ?? '',
            'enabled' => (bool)($banner['enabled'] ?? false),
        ];

        $record->update(['value' => $value]);
    }
};
