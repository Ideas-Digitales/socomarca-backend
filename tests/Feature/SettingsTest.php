<?php

use App\Models\User;
use App\Models\Siteinfo;
use App\Services\VatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\SettingsScenario;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

test('un admin puede ver la configuración de precios por cantidad', function () {
    Siteinfo::updateOrCreate(
        ['key' => 'prices_settings'],
        ['value' => ['min_max_quantity_enabled' => true]]
    );

    Sanctum::actingAs(SettingsScenario::make()->admin);
    $response = getJson('/api/settings/prices');

    $response->assertStatus(200)
        ->assertJson([
            'min_max_quantity_enabled' => true,
        ]);
});

test('un admin puede actualizar la configuración de precios por cantidad', function () {
    Siteinfo::updateOrCreate(
        ['key' => 'prices_settings'],
        ['value' => ['min_max_quantity_enabled' => true]]
    );

    Sanctum::actingAs(SettingsScenario::make()->admin);
    $response = putJson('/api/settings/prices', [
            'min_max_quantity_enabled' => false,
        ]);

    $response->assertStatus(200);

    assertDatabaseHas('siteinfo', [
        'key' => 'prices_settings',
    ]);
    $this->assertTrue(Siteinfo::where('key', 'prices_settings')->first()->value['min_max_quantity_enabled'] === false);
});

test('un usuario sin el permiso read-content-settings no puede acceder a la configuración', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);
    $response = getJson('/api/settings/prices');

    $response->assertStatus(403);
});

describe('VAT settings', function () {
    it('returns the seeded default rate when no setting exists', function () {
        Sanctum::actingAs(SettingsScenario::make()->admin, ['api-access']);

        getJson(route('settings.vat.get'))
            ->assertOk()
            ->assertJson(['rate' => (float) config('vat.rate')]);
    });

    it('returns the rate stored in siteinfo', function () {
        Siteinfo::updateOrCreate(
            ['key' => VatService::SETTINGS_KEY],
            ['value' => ['rate' => 12.5]]
        );

        Sanctum::actingAs(SettingsScenario::make()->admin, ['api-access']);

        getJson(route('settings.vat.get'))
            ->assertOk()
            ->assertJson(['rate' => 12.5]);
    });

    it('lets an admin update the rate', function () {
        Sanctum::actingAs(SettingsScenario::make()->admin, ['api-access']);

        putJson(route('settings.vat.update'), ['rate' => 21])
            ->assertOk();

        expect(app(VatService::class)->rate())->toBe(21.0);
    });

    it('rejects a rate outside the 0-100 range', function () {
        Sanctum::actingAs(SettingsScenario::make()->admin, ['api-access']);

        putJson(route('settings.vat.update'), ['rate' => 120])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('rate');
    });

    it('denies access to a user without the settings permissions', function () {
        Sanctum::actingAs(User::factory()->create(), ['api-access']);

        getJson(route('settings.vat.get'))->assertForbidden();
        putJson(route('settings.vat.update'), ['rate' => 19])->assertForbidden();
    });
});
