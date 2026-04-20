<?php
use App\Models\User;

describe('Payment Methods API', function () {
    it('should require authentication for index', function () {
        $response = $this->getJson(route('payment-methods.index'));
        $response->assertStatus(401);
    });

    it('should require permission for index', function () {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        $response = $this->getJson(route('payment-methods.index'));
        $response->assertStatus(403);
    });

    it('should allow access to index with permission and return correct fields', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-payment-methods');
        $this->actingAs($user, 'sanctum');
        $response = $this->getJson(route('payment-methods.index'));
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
        // Also ensure we get active payment methods
        $paymentMethods = \App\Models\PaymentMethod::where('active', true)->get();
        expect(count($response->json('data')))->toBe($paymentMethods->count());
    });

    it('should require authentication for update', function () {
        $response = $this->putJson(route('payment-methods.update', ['id' => 1]), [
            // ...data...
        ]);
        $response->assertStatus(401);
    });

    it('should require permission for update', function () {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        $response = $this->putJson(route('payment-methods.update', ['id' => 1]), [
            // ...data...
        ]);
        $response->assertStatus(403);
    });

    it('should allow update with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('update-payment-methods');
        $this->actingAs($user, 'sanctum');

        $paymentMethod = \App\Models\PaymentMethod::first();

        $response = $this->putJson(route('payment-methods.update', ['id' => $paymentMethod->id]), [
            'active' => true, // agrega todos los campos requeridos por tu validador
        ]);
        $response->assertStatus(200);
    });
});