<?php

namespace Database\Seeders;

use App\Models\Siteinfo;
use Illuminate\Database\Seeder;

class SiteInfoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $entries = [
            'WEBPAY_INFO' => [
                'value' => [
                    'WEBPAY_COMMERCE_CODE' => '597055555532',
                    'WEBPAY_API_KEY' => '579B532A7440BB0C9079DED94D31EA1615BACEB56610332264630D42D0A36B1C',
                    'WEBPAY_ENVIRONMENT' => 'integration',
                    'WEBPAY_RETURN_URL' => config('app.url') . '/webpay/return',
                ],
                'content' => 'Informacion de entorno webpay',
            ],
        ];

        foreach ($entries as $key => $data) {
            Siteinfo::firstOrCreate(
                ['key' => $key],
                [
                    'value' => $data['value'],
                    'content' => $data['content'],
                ]
            );
        }

        Siteinfo::firstOrCreate(
            ['key' => 'prices_settings'],
            ['value' => ['min_max_quantity_enabled' => false]]
        );

        Siteinfo::firstOrCreate(
            ['key' => 'upload_settings'],
            [
                'value' => ['max_upload_size' => 50], // En MB
                'content' => 'Configuración de tamaño máximo para subida de archivos'
            ]
        );
    }
}
