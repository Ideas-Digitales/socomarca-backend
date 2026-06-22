<?php

namespace Database\Seeders;

use App\Models\Municipality;
use App\Models\Region;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RegionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {


        $regions = $this->getFakeRegions();
        foreach ($regions as $region) {
            Region::updateOrCreate(
                [
                    'code' => $region->code,
                ],
                [
                    'name' => $region->name,
                    'status' => $region->status,
                ]
            );
        }

        $comunas = $this->getFakeMunicipalities();
        foreach ($comunas as $comuna) {
            Municipality::updateOrCreate(
                ['code' => $comuna->code],
                [
                'name' => $comuna->name,
                'region_id' => $comuna->region_id,
                'status' => $comuna->region_id == 7
                ]
            );
        }
    }

    private function getFakeRegions()
    {
        $regionsJsonFile = Storage::disk('local')->get('fake_seed_data/regions.json');
        return json_decode($regionsJsonFile);
    }


    private function getFakeMunicipalities()
    {
        $municipalitiesJsonFile = Storage::disk('local')->get('fake_seed_data/municipalities.json');
        return json_decode($municipalitiesJsonFile);
    }
}
