<?php

use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Bus;
use App\Jobs\SendBulkNotification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo('create-notifications');

    $this->customer1 = User::factory()->create();
    $this->customer1->assignRole('customer');

    $this->customer2 = User::factory()->create();
    $this->customer2->assignRole('customer');

    $this->customer3 = User::factory()->create();
    $this->customer3->assignRole('customer');
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
            Mail::fake();
            
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
            Mail::fake();
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
                ])
                ->assertJson([
                    'title' => 'Nuevo producto agregado a nuestro almacén',
                    'message' => 'Queremos que compres nuestro nuevo producto en Socomarca',
                    'recipients_count' => 3
                ]);
        });

        it('should queue notification emails for all customers', function () {
            Mail::fake();
            (new \App\Jobs\SendBulkNotification('Test notification', 'Test message'))->handle();
            Mail::assertQueued(NotificationMail::class);
            Mail::assertQueuedCount(3);
        });

        it('should send email to each customer with correct data', function () {
            Mail::fake();
            $this->actingAs($this->admin, 'sanctum');
            $title = 'Test notification';
            $message = 'Test message';
            $response = $this->postJson(route('notifications.store'), [
                'title' => $title,
                'message' => $message
            ]);
            $response->assertStatus(201);
            Mail::assertQueued(NotificationMail::class, function ($mail) use ($title, $message) {
                return $mail->title === $title &&
                       $mail->notificationMessage === $message &&
                       in_array($mail->user->id, [$this->customer1->id, $this->customer2->id, $this->customer3->id]);
            });
        });

        it('should handle case when no customers exist', function () {
            Mail::fake();
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
            Mail::assertNothingQueued();
        });

        it('should dispatch SendBulkNotification job', function () {
            Bus::fake();
            $this->actingAs($this->admin, 'sanctum');
            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Test notification',
                'message' => 'Test message'
            ]);
            $response->assertStatus(201);
            Bus::assertDispatched(SendBulkNotification::class, function ($job) {
                return $job->title === 'Test notification' && $job->message === 'Test message';
            });
        });
    });
});