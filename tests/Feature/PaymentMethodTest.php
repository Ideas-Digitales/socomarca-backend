<?php

use App\Models\PaymentMethod;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

describe('Payment Methods API', function () {
    it('should require authentication for index', function () {
        $response = getJson(route('payment-methods.index'));
        $response->assertStatus(401);
    });

    it('should require permission for index', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        $response = getJson(route('payment-methods.index'));
        $response->assertStatus(403);
    });

    it('should allow access to index with permission and return correct fields', function () {
        $user = createUserWithPermissions(['read-all-payment-methods']);
        Sanctum::actingAs($user, ['api-access']);

        $response = getJson(route('payment-methods.index'));
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'active',
                    'code',
                    'created_at',
                    'updated_at'
                ]
            ]
        ]);

        $paymentMethods = PaymentMethod::where('active', true)->get();
        expect(count($response->json('data')))->toBe($paymentMethods->count());
    });

    it('should require authentication for update', function () {
        $response = putJson(route('payment-methods.update', ['id' => 1]), []);
        $response->assertStatus(401);
    });

    it('should require permission for update', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api-access']);

        $response = putJson(route('payment-methods.update', ['id' => 1]), []);
        $response->assertStatus(403);
    });

    it('should allow update with permission', function () {
        $user = createUserWithPermissions(['update-payment-methods']);
        Sanctum::actingAs($user, ['api-access']);

        $paymentMethod = PaymentMethod::first();

        $response = putJson(route('payment-methods.update', ['id' => $paymentMethod->id]), [
            'active' => true,
        ]);
        $response->assertStatus(200);
    });
});
