<?php

use App\Models\Address;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\ProfileScenario;

use function Pest\Laravel\getJson;

it('allows a user to view their own profile information', function () {
    $scenario = ProfileScenario::make();

    $user = User::factory()
        ->has(
            Address::factory([
                'type' => 'shipping',
                'is_default' => 1,
            ])->count(1)
        )->has(
            Address::factory([
                'type' => 'billing',
                'is_default' => 1,
            ])->count(1)
        )->has(
            Address::factory([
                'type' => 'shipping',
                'is_default' => 0,
            ])->count(2)
        )->create();

    Sanctum::actingAs($user, ['api-access']);

    getJson('/api/profile')
        ->assertStatus(200)
        ->assertJsonStructure($scenario->profileStructure)
        ->assertJsonFragment(['rut' => $user->rut])
        ->assertJson(fn (AssertableJson $json) =>
            $json->where('rut', $user->rut)
                ->where('name', $user->name)
                ->where('billing_address.id', $user->billing_address->id)
                ->where('default_shipping_address.id', $user->default_shipping_address->id)
                ->etc()
        );
});

it('allows a user to view their own profile even without associated addresses', function () {
    $user = User::factory()->create();
    Address::where('user_id', $user->id)->delete();

    Sanctum::actingAs($user, ['api-access']);

    getJson('/api/profile')
        ->assertStatus(200)
        ->assertJson(fn (AssertableJson $json) =>
            $json->where('rut', $user->rut)
                ->where('name', $user->name)
                ->where('billing_address', null)
                ->where('default_shipping_address', null)
                ->etc()
        );
});
