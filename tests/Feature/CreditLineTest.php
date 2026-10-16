<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('should return valid credit line successfully', function () {
    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'user_code' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    Sanctum::actingAs($user, ['api-access']);
    $baseUrl = config('random.url');
    Http::fake([
        ...randomGrantedCreditStub($baseUrl, '12345678-9', 'CM', 50000000),
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 450000000,
            'CRSDVU' => 5915690,
            'CRSDVV' => 705736,
            'CRSDCU' => 0,
            'CRSDCV' => 0,
        ], 200),
    ]);

    getJson(route('users.credit-lines', $user->id))
        ->assertStatus(200)
        ->assertJson([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 50000000,
            'CRSDVU' => 5915690,
            'CRSDVV' => 705736,
            'CRSDCU' => 0,
            'CRSDCV' => 0,
            'status' => 'unblocked',
        ]);
});

it('returns the granted credit with zero usage when the summary does not exist', function () {
    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'user_code' => '12345678-9', 'branch_code' => 'L01']);
    $user->givePermissionTo('read-own-credit-lines');
    Sanctum::actingAs($user, ['api-access']);
    $baseUrl = config('random.url');
    Http::fake([
        ...randomGrantedCreditStub($baseUrl, '12345678-9', 'L01', 50000000),
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([
            'message' => 'No se encuentra el recurso',
        ], 404),
    ]);

    getJson(route('users.credit-lines', $user->id))
        ->assertOk()
        ->assertJson([
            'KOEN' => '12345678-9',
            'SUEN' => 'L01',
            'CRSD' => 50000000,
            'CRSDVU' => 0,
            'CRSDVV' => 0,
            'CRSDCU' => 0,
            'CRSDCV' => 0,
        ]);
});

it('should return 500 when Random API returns 200 but with invalid response', function () {
    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'user_code' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    Sanctum::actingAs($user, ['api-access']);

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([], 200),
    ]);

    getJson(route('users.credit-lines', $user->id))
        ->assertJson([
            'message' => 'No se pudo obtener el crédito del cliente',
            'detail' => 'Error de comunicación con el servicio de Random API',
        ])
        ->assertStatus(500);
});

it('should return 500 when Random API fails with a code different from 404', function () {
    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'user_code' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    Sanctum::actingAs($user, ['api-access']);

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([], 500),
    ]);

    getJson(route('users.credit-lines', $user->id))
        ->assertJson([
            'message' => 'No se pudo obtener el crédito del cliente',
            'detail' => 'Error de comunicación con el servicio de Random API',
        ])
        ->assertStatus(500);
});

it('should return 404 when the branch has no granted credit', function () {
    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'user_code' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    Sanctum::actingAs($user, ['api-access']);
    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/web32/entidades*" => Http::response([
            [
                'KOEN' => '12345678-9',
                'SUEN' => 'L01',
                'CRTO' => 50000000,
                'CRSD' => 50000000,
            ],
        ], 200),
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 450000000,
            'CRSDVU' => 0,
            'CRSDVV' => 0,
        ], 200),
    ]);

    getJson(route('users.credit-lines', $user->id))
        ->assertJson([
            'message' => 'No se pudo obtener el crédito del cliente',
            'detail' => 'Recurso no encontrado en Random API',
        ])
        ->assertNotFound();
});

it('should return local credit state and skips Random API when credit line is blocked', function () {
    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'user_code' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    Sanctum::actingAs($user, ['api-access']);

    $localState = [
        'KOEN' => '12345678-9',
        'SUEN' => 'CM',
        'CRSD' => 1000,
        'CRSDVU' => 100,
        'CRSDVV' => 50,
        'CRSDCU' => 0,
        'CRSDCV' => 0,
    ];

    $user->creditLines()->create([
        'branch_code' => 'CM',
        'state' => $localState,
        'is_blocked' => true,
    ]);

    Http::fake();

    $response = getJson(route('users.credit-lines', $user->id));

    Http::assertNothingSent();

    $response->assertStatus(200)
        ->assertJson($localState);
});

it('should call Random API when local credit line exists but is not blocked', function () {
    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'user_code' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    Sanctum::actingAs($user, ['api-access']);

    $localState = [
        'KOEN' => '12345678-9',
        'SUEN' => 'CM',
        'CRSD' => 500,
    ];

    $user->creditLines()->create([
        'branch_code' => 'CM',
        'state' => $localState,
        'is_blocked' => false,
    ]);

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/login" => Http::response(['token' => 'fake-test-token'], 200),
        ...randomGrantedCreditStub($baseUrl, '12345678-9', 'CM', 2000),
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 450000000,
            'CRSDVU' => 100,
            'CRSDVV' => 50,
            'CRSDCU' => 0,
            'CRSDCV' => 0,
        ], 200),
    ]);

    $response = getJson(route('users.credit-lines', $user->id));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($baseUrl) {
        return str_starts_with($request->url(), "{$baseUrl}/gestion/credito/resumen/");
    });
    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($baseUrl) {
        return str_contains($request->url(), "{$baseUrl}/web32/entidades");
    });

    $response->assertStatus(200)
        ->assertJson([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 2000,
            'CRSDVU' => 100,
            'CRSDVV' => 50,
            'CRSDCU' => 0,
            'CRSDCV' => 0,
        ]);
});
