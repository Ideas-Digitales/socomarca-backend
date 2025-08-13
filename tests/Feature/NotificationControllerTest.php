<?php

use App\Jobs\SendPushNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use App\Notifications\PushNotification;
use Kreait\Firebase\Contract\Messaging;


uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo('create-notifications');

    $this->customer1 = User::factory()->create();
    $this->customer1->fcm_token = 'token1';
    $this->customer1->assignRole('customer');
    $this->customer1->save(); 

    $this->customer2 = User::factory()->create();
    $this->customer2->fcm_token = 'token2';
    $this->customer2->assignRole('customer');
    $this->customer2->save(); 

    $this->customer3 = User::factory()->create();
    $this->customer3->fcm_token = 'token3';
    $this->customer3->assignRole('customer');
    $this->customer3->save(); 

    $this->mock(Messaging::class, function ($mock) {
        $mock->shouldReceive('send')->andReturn('msg-id-123');
        $mock->shouldReceive('sendAll')->andReturnNull();
    });
});

describe('Notification API', function () {
    describe('Authorization', function () {
        it('should require authentication for store', function () {
            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Test notification',
                'message' => 'Test message'
            ]);
            $response->assertStatus(401);
        });

        it('should require permission for store', function () {
            $user = User::factory()->create();
            $this->actingAs($user, 'sanctum');
            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Test notification',
                'message' => 'Test message'
            ]);
            $response->assertStatus(403);
        });

        it('should allow access to store with permission', function () {
            $user = User::factory()->create();
            $user->givePermissionTo('create-notifications');
            $this->actingAs($user, 'sanctum');
            $user->refresh();

            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Test notification',
                'message' => 'Test message'
            ]);
            $response->assertStatus(201);
        });
    });

    describe('Functional', function () {
        it('should validate required fields', function () {
            $this->actingAs($this->admin, 'sanctum');
            $response = $this->postJson(route('notifications.store'), []);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['title', 'message']);
        });

        it('should validate title max length', function () {
            $this->actingAs($this->admin, 'sanctum');
            $response = $this->postJson(route('notifications.store'), [
                'title' => str_repeat('a', 256),
                'message' => 'Valid message'
            ]);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['title']);
        });

        it('should validate message max length', function () {
            $this->actingAs($this->admin, 'sanctum');
            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Valid title',
                'message' => str_repeat('a', 1001)
            ]);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['message']);
        });

        it('should create notification and return correct structure', function () {
            Notification::fake();
            $this->actingAs($this->admin, 'sanctum');
            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Nuevo producto agregado a nuestro almacén',
                'message' => 'Queremos que compres nuestro nuevo producto en Socomarca'
            ]);
            $response->assertStatus(201)
                ->assertJsonStructure([
                    'title',
                    'message',
                    'recipients_count',
                    'created_at'
                ]);
               
        });

        it('should send push notification to all customers with fcm_token', function () {
            Notification::fake();
            $this->actingAs($this->admin, 'sanctum');
            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Test notification',
                'message' => 'Test message'
            ]);
            $response->assertStatus(201);

            // Ejecuta el Job manualmente
            (new SendPushNotification('Test notification', 'Test message'))->handle();

            Notification::assertSentTo(
                [$this->customer1, $this->customer2, $this->customer3],
                PushNotification::class,
                function ($notification, $channels, $notifiable) {
                    return $notification->title === 'Test notification'
                        && $notification->body === 'Test message';
                }
            );
        });

        it('should handle case when no customers exist', function () {
            Notification::fake();
            User::role('customer')->delete();
            $this->actingAs($this->admin, 'sanctum');
            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Test notification',
                'message' => 'Test message'
            ]);
            $response->assertStatus(201)
                ->assertJson([
                    'recipients_count' => 0
                ]);
            Notification::assertNothingSent();
        });
    });
});