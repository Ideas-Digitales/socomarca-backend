<?php

use App\Jobs\SendPushNotification;
use App\Models\FcmNotificationHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use App\Notifications\PushNotification;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MulticastSendReport;



uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo('create-notifications');

    $this->customer1 = User::factory()->create([
        'fcm_token' => 'token1',
        'is_active' => true,
    ]);
    
    $this->customer1->assignRole('customer');
    $this->customer1->save(); 

    $this->customer2 = User::factory()->create([
        'fcm_token' => 'token2',
        'is_active' => true,
    ]);
    $this->customer2->assignRole('customer');
    $this->customer2->save(); 

    $this->customer3 = User::factory()->create([
        'fcm_token' => 'token3',
        'is_active' => true,
    ]);
    $this->customer3->assignRole('customer');
    $this->customer3->save(); 

    // Crear un fichero de credenciales fake en storage para que el provider lo encuentre
    $credsPath = storage_path('app/private/firebase/credentials.json');
    if (!is_dir(dirname($credsPath))) {
        mkdir(dirname($credsPath), 0755, true);
    }
    $fakeCreds = [
        'type' => 'service_account',
        'project_id' => 'fake-project',
        'private_key_id' => 'fake',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
        'client_email' => 'sa@fake.iam.gserviceaccount.com',
        'client_id' => 'fake-client',
    ];
    file_put_contents($credsPath, json_encode($fakeCreds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $this->mock(Messaging::class, function ($mock) {
        $mock->shouldReceive('send')->andReturn('msg-id-123');
        $mock->shouldReceive('sendAll')->andReturnNull();
        $report = (new \ReflectionClass(MulticastSendReport::class))->newInstanceWithoutConstructor();
        $mock->shouldReceive('sendMulticast')->andReturn($report);
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

            SendPushNotification::dispatchSync('Test notification', 'Test message', $this->admin->id);

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

        it('save the history when sending an FCM notification', function () {
            Notification::fake();
            $this->actingAs($this->admin, 'sanctum');

            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Historial FCM',
                'message' => 'Mensaje historial'
            ]);
            $response->assertStatus(201);

            $this->assertDatabaseHas('fcm_notification_histories', [
                'user_id' => $this->admin->id,
                'title' => 'Historial FCM',
                'message' => 'Mensaje historial',
            ]);
        });

        it('returns the notification history', function () {
            $admin = User::factory()->create();
            $admin->givePermissionTo('create-notifications');

            FcmNotificationHistory::create([
                'user_id' => $admin->id,
                'title' => 'Historial FCM',
                'message' => 'Mensaje historial',
                'sent_at' => now(),
            ]);

            $this->actingAs($admin, 'sanctum');
            $response = $this->getJson(route('notifications.index'));

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'title' => 'Historial FCM',
                    'message' => 'Mensaje historial',
                ]);
        });

        it('does not save history if user does not have permission', function () {
            $user = User::factory()->create();
            $this->actingAs($user, 'sanctum');

            $response = $this->postJson(route('notifications.store'), [
                'title' => 'Sin permiso',
                'message' => 'No debe guardar'
            ]);

            $response->assertStatus(403);
            $this->assertDatabaseMissing('fcm_notification_histories', [
                'title' => 'Sin permiso',
                'message' => 'No debe guardar',
            ]);
        });
    });
});