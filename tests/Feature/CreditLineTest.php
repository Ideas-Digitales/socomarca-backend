<?php

use App\Models\User;
use App\Services\RandomApiService;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('it returns valid credit line successfully', function () {
    /** @var TestCase $this */

    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 50092358399999.99,
            'CRSDVU' => 5915690,
            'CRSDVV' => 705736,
            'CRSDCU' => 0,
            'CRSDCV' => 0
        ], 200)
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson(route('users.credit-lines', $user->id));

    $response->assertStatus(200)
        ->assertJson([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 50092358399999.99,
            'CRSDVU' => 5915690,
            'CRSDVV' => 705736,
            'CRSDCU' => 0,
            'CRSDCV' => 0
        ]);
});

test('it returns 500 when Random API returns 200 but with invalid response', function () {
    /** @var TestCase $this */

    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([], 200)
    ]);

    $response = $this->actingAs($user)->getJson(route('users.credit-lines', $user->id));
    $response
        ->assertJson([
            'message' => 'No se pudo obtener el crédito del cliente',
            'detail' => 'Error de comunicación con el servicio de Random API'
        ])
        ->assertStatus(500);
});

test('it returns 500 when Random API fails with a code different from 404', function () {
    /** @var TestCase $this */

    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');

    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([], 500)
    ]);

    $response = $this->actingAs($user)->getJson(route('users.credit-lines', $user->id));
    $response
        ->assertJson([
            'message' => 'No se pudo obtener el crédito del cliente',
            'detail' => 'Error de comunicación con el servicio de Random API'
        ])
        ->assertStatus(500);
});

test('it returns 404 when credit is not found', function () {
    /** @var TestCase $this */

    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    $baseUrl = config('random.url');
    Http::fake([
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([
            "message" => "No se encuentra el recurso",
            "errorId" => "w1k-2OBS",
            "logUrl" => "http://localhost:3111/xlogger?reqId=QqMEt9Ic"
        ], 404)
    ]);

    $response = $this->actingAs($user)->getJson(route('users.credit-lines', $user->id));
    $response
        ->assertJson([
            'message' => 'No se pudo obtener el crédito del cliente',
            'detail' => 'Recurso no encontrado en Random API'
        ])
        ->assertNotFound();
});

test('it returns local credit state and skips Random API when credit line is blocked', function () {
    /** @var TestCase $this */

    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    
    $localState = [
        'KOEN' => '12345678-9',
        'SUEN' => 'CM',
        'CRSD' => 1000,
        'CRSDVU' => 100,
        'CRSDVV' => 50,
        'CRSDCU' => 0,
        'CRSDCV' => 0
    ];

    $user->creditLines()->create([
        'branch_code' => 'CM',
        'state' => $localState,
        'is_blocked' => true,
    ]);

    Http::fake();

    $response = $this->actingAs($user, 'sanctum')->getJson(route('users.credit-lines', $user->id));

    Http::assertNothingSent();

    $response->assertStatus(200)
        ->assertJson($localState);
});

test('it calls Random API when local credit line exists but is not blocked', function () {
    /** @var TestCase $this */

    /** @var User $user */
    $user = User::factory()->create(['rut' => '12345678-9', 'branch_code' => 'CM']);
    $user->givePermissionTo('read-own-credit-lines');
    
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
        "{$baseUrl}/gestion/credito/resumen/*" => Http::response([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 2000,
            'CRSDVU' => 100,
            'CRSDVV' => 50,
            'CRSDCU' => 0,
            'CRSDCV' => 0
        ], 200)
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson(route('users.credit-lines', $user->id));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($baseUrl) {
        return str_starts_with($request->url(), "{$baseUrl}/gestion/credito/resumen/");
    });
    
    $response->assertStatus(200)
        ->assertJson([
            'KOEN' => '12345678-9',
            'SUEN' => 'CM',
            'CRSD' => 2000,
            'CRSDVU' => 100,
            'CRSDVV' => 50,
            'CRSDCU' => 0,
            'CRSDCV' => 0
        ]);
});
