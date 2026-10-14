<?php

namespace App\Console\Commands;

use App\Models\Municipality;
use App\Models\Region;
use App\Services\RandomApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SyncRegionsAndMunicipalities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'random:sync-regions-municipalities';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs regions and municipalities from Random ERP API';

    /**
     * Execute the console command.
     */
    public function handle(RandomApiService $randomApi): int
    {
        /** @var Collection<int, array{CODIGO: string, NOMBRE: string, NIVEL: int, LLAVE: string}> $territorios */
        $territorios = collect($randomApi->getTerritorios(3)->json('data'));

        /** @var Collection<int, array{CODIGO: string, NOMBRE: string, NIVEL: int, LLAVE: string}> $regiones */
        $regiones = $territorios->where('NIVEL', 2)->values();

        /** @var Collection<int, array{CODIGO: string, NOMBRE: string, NIVEL: int, LLAVE: string}> $comunas */
        $comunas = $territorios->where('NIVEL', 3)->values();

        $this->syncRegions($regiones);
        $this->syncMunicipalities($comunas);

        return self::SUCCESS;
    }

    /**
     * Syncs regions from Random API to local database
     *
     * @param Collection<int, array{CODIGO: string, NOMBRE: string, NIVEL: int, LLAVE: string}> $regiones
     *
     * @return void
     */
    private function syncRegions(Collection $regiones): void
    {
        $created = 0;
        $updated = 0;

        foreach ($regiones as $apiRegion) {
            $code = str_pad((string) $apiRegion['CODIGO'], 2, '0', STR_PAD_LEFT);
            $systemRegion = Region::firstOrNew(['code' => $code]);
            $wasExisting = $systemRegion->exists;

            $systemRegion->random_key = $apiRegion['LLAVE'];
            $systemRegion->random_name = $apiRegion['NOMBRE'];

            if (! $wasExisting) {
                $systemRegion->name = $apiRegion['NOMBRE'];
                $systemRegion->status = true;
            }

            $systemRegion->save();
            $wasExisting ? $updated++ : $created++;
        }

        $this->info('--- Regions summary ---');
        $this->info("API regions: {$regiones->count()}");
        $this->info("Created: {$created}");
        $this->info("Updated: {$updated}");
        $this->newLine();
    }

    /**
     * Syncs municipalities (comunas) from Random API to local database
     *
     * @param Collection<int, array{CODIGO: string, NOMBRE: string, NIVEL: int, LLAVE: string}> $comunas
     *
     * @return void
     */
    private function syncMunicipalities(Collection $comunas): void
    {
        $created = 0;
        $updated = 0;
        $skipped = [];

        // LLAVE for regions is CL/{apiCode}; comunas are CL/{apiCode}/{comunaCode}
        $regionByApiKey = Region::query()
            ->where('random_key', '!=', 'missing')
            ->get()
            ->keyBy('random_key');

        foreach ($comunas as $apiComuna) {
            $parts = explode('/', $apiComuna['LLAVE']);
            $regionApiKey = isset($parts[0], $parts[1])
                ? "{$parts[0]}/{$parts[1]}"
                : null;
            $systemRegion = $regionApiKey !== null
                ? $regionByApiKey->get($regionApiKey)
                : null;

            if ($systemRegion === null) {
                $skipped[] = "{$apiComuna['NOMBRE']} (no region for LLAVE {$apiComuna['LLAVE']})";
                continue;
            }

            $systemMunicipality = Municipality::where('random_key', $apiComuna['LLAVE'])->first()
                ?? $this->findMunicipalityByName($apiComuna['NOMBRE'], $systemRegion->id);

            $wasExisting = $systemMunicipality !== null;

            if (! $wasExisting) {
                $systemMunicipality = new Municipality([
                    'name' => $apiComuna['NOMBRE'],
                    'region_id' => $systemRegion->id,
                    'status' => true,
                ]);
            }

            $systemMunicipality->region_id = $systemRegion->id;
            $systemMunicipality->random_key = $apiComuna['LLAVE'];
            $systemMunicipality->random_name = $apiComuna['NOMBRE'];
            $systemMunicipality->save();

            if ($wasExisting) {
                $updated++;
            } else {
                $created++;
                $this->line("Created municipality: {$systemMunicipality->name} @ {$systemRegion->name} <= {$apiComuna['LLAVE']}");
            }
        }

        $this->info('--- Municipalities summary ---');
        $this->info("API comunas: {$comunas->count()}");
        $this->info("Created: {$created}");
        $this->info("Updated: {$updated}");
        $this->info('Skipped: ' . count($skipped));

        if ($skipped !== []) {
            $this->newLine();
            $this->warn('Skipped list:');
            foreach ($skipped as $item) {
                $this->line("  - {$item}");
            }
        }
    }

    /**
     * Accent-insensitive best match within a region.
     * API names drop accents/ñ (e.g. NuNoa, PeNalolen) and omit spaces (Chol Chol vs Cholchol).
     *
     * @param string $apiName
     * @param int $regionId
     *
     * @return Municipality|null
     */
    private function findMunicipalityByName(string $apiName, int $regionId): ?Municipality
    {
        $needle = $this->normalizeName($apiName);
        $candidates = Municipality::where('region_id', $regionId)->get();

        $exact = $candidates->first(
            fn (Municipality $c) => $this->normalizeName($c->name) === $needle
        );
        if ($exact !== null) {
            return $exact;
        }

        $bestContainment = null;
        $bestContainmentScore = 0.0;
        $bestFuzzy = null;
        $bestFuzzyScore = 0.0;

        foreach ($candidates as $candidate) {
            $haystack = $this->normalizeName($candidate->name);
            similar_text($needle, $haystack, $percent);

            if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
                if ($percent > $bestContainmentScore) {
                    $bestContainmentScore = $percent;
                    $bestContainment = $candidate;
                }
                continue;
            }

            if ($percent > $bestFuzzyScore) {
                $bestFuzzyScore = $percent;
                $bestFuzzy = $candidate;
            }
        }

        if ($bestContainment !== null) {
            return $bestContainment;
        }

        return $bestFuzzyScore >= 85.0 ? $bestFuzzy : null;
    }

    /**
     * Normalize name
     *
     * @param string $name
     *
     * @return string
     */
    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
            "'" => '', '´' => '', '`' => '',
        ]);
        // Collapse spaces so "Chol Chol" can match "Cholchol"
        $name = preg_replace('/\s+/', '', $name) ?? $name;

        return $name;
    }
}
