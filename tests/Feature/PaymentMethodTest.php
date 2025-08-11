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

    it('should allow access to index with permission', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('read-all-payment-methods');
        $this->actingAs($user, 'sanctum');
        $response = $this->getJson(route('payment-methods.index'));
        $response->assertStatus(200);
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

        $paymentMethod = \App\Models\PaymentMethod::factory()->create();

        $response = $this->putJson(route('payment-methods.update', ['id' => $paymentMethod->id]), [
            'active' => true, // agrega todos los campos requeridos por tu validador
        ]);
        $response->assertStatus(200);
    });
});