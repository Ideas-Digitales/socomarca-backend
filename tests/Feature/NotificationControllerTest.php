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

    // Write a fake credentials file to storage so the provider can find it.
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

        it('carries the history id in the push payload so the client can deduplicate it', function () {
            $history = FcmNotificationHistory::create([
                'user_id' => $this->admin->id,
                'title' => 'Con id',
                'message' => 'Cuerpo',
                'sent_at' => now(),
            ]);

            $payload = (new PushNotification('Con id', 'Cuerpo', $history->id))
                ->toFcm($this->customer1)
                ->toArray()['data'];

            // The client receives the same notification through the push and through
            // the history; without this id it cannot tell they are the same one and
            // lists it twice.
            expect($payload)->toHaveKey('notification_id');
            expect($payload['notification_id'])->toBe((string) $history->id);
            expect($payload['title'])->toBe('Con id');
            expect($payload['body'])->toBe('Cuerpo');
        });

        it('omits the id from the push payload when it was not given', function () {
            $payload = (new PushNotification('Sin id', 'Cuerpo'))
                ->toFcm($this->customer1)
                ->toArray()['data'];

            // The parameter is optional: without it the push still goes out, the
            // client just cannot deduplicate it.
            expect($payload)->not->toHaveKey('notification_id');
            expect($payload['title'])->toBe('Sin id');
        });

        it('sends the push with the id of the history row it just created', function () {
            Notification::fake();

            SendPushNotification::dispatchSync('Con historial', 'Cuerpo', $this->admin->id);

            $history = FcmNotificationHistory::where('title', 'Con historial')->firstOrFail();

            Notification::assertSentTo(
                $this->customer1,
                PushNotification::class,
                fn ($notification) => $notification->notificationId === $history->id
            );
        });

        it('honours the per_page asked for by the client', function () {
            $admin = User::factory()->create();
            $admin->givePermissionTo('read-all-notifications');

            foreach (range(1, 8) as $i) {
                FcmNotificationHistory::create([
                    'user_id' => $admin->id,
                    'title' => "Notificación {$i}",
                    'message' => 'Mensaje',
                    'sent_at' => now()->subMinutes($i),
                ]);
            }

            $this->actingAs($admin, 'sanctum');

            // It used to be ignored and always returned 20 rows.
            $response = $this->getJson(route('notifications.index', ['per_page' => 5]));

            $response->assertStatus(200);
            expect($response->json('per_page'))->toBe(5);
            expect($response->json('data'))->toHaveCount(5);
            expect($response->json('total'))->toBe(8);
        });

        it('clamps per_page so nobody can ask for the whole table', function () {
            $admin = User::factory()->create();
            $admin->givePermissionTo('read-all-notifications');
            $this->actingAs($admin, 'sanctum');

            expect($this->getJson(route('notifications.index', ['per_page' => 999]))->json('per_page'))
                ->toBe(100);
            expect($this->getJson(route('notifications.index', ['per_page' => 0]))->json('per_page'))
                ->toBe(1);
        });

        it('defaults to 20 per page when none is asked for', function () {
            $admin = User::factory()->create();
            $admin->givePermissionTo('read-all-notifications');
            $this->actingAs($admin, 'sanctum');

            expect($this->getJson(route('notifications.index'))->json('per_page'))->toBe(20);
        });

        it('returns the notification history', function () {
            $admin = User::factory()->create();
            $admin->givePermissionTo('create-notifications');
            $admin->givePermissionTo('read-all-notifications');

            FcmNotificationHistory::create([
                'user_id' => $admin->id,
                'title' => 'Historial FCM',
                'message' => 'Mensaje historial',
                'sent_at' => now(),
            ]);

            $this->actingAs($admin, 'sanctum');
            $response = $this->getJson(route('notifications.index'));

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'current_page',
                    'data' => [
                        '*' => [
                            'id',
                            'user_id',
                            'title',
                            'message',
                            'viewed',
                            'sent_at'
                        ]
                    ],
                    'first_page_url',
                    'from',
                    'last_page',
                    'last_page_url',
                    'links' => [
                        '*' => [
                            'url',
                            'label',
                            'active'
                        ]
                    ],
                    'next_page_url',
                    'path',
                    'per_page',
                    'prev_page_url',
                    'to',
                    'total'
                ])
                ->assertJsonFragment([
                    'title' => 'Historial FCM',
                    'message' => 'Mensaje historial',
                ])
                ->assertJsonPath('data.0.viewed', false)
                ->assertJsonPath('current_page', 1)
                ->assertJsonPath('per_page', 20)
                ->assertJsonPath('total', 1);
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