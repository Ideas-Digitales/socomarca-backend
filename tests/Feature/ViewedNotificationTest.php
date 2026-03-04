<?php

namespace Tests\Feature;

use App\Models\FcmNotificationHistory;
use App\Models\User;
use App\Models\ViewedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_store_viewed_notifications(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('create-viewed-notifications');
        $notifications = FcmNotificationHistory::factory()->count(3)->create();

        $response = $this->actingAs($user)->postJson('/api/viewed-notifications/batch', [
            'resources' => [
                ['fcm_notification_id' => $notifications[0]->id],
                ['fcm_notification_id' => $notifications[1]->id],
            ]
        ]);

        $response->assertStatus(201)
                 ->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('viewed_notifications', 2);
    }

    public function test_only_user_who_viewed_sees_notification_as_viewed(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $notification = FcmNotificationHistory::factory()->create();

        // Assign permissions to users
        $userA->givePermissionTo('create-viewed-notifications');
        $userB->givePermissionTo('create-viewed-notifications');

        // User A marks as viewed
        $this->actingAs($userA)->postJson('/api/viewed-notifications/batch', [
            'resources' => [['fcm_notification_id' => $notification->id]]
        ])->assertStatus(201);

        // Check User A sees as viewed
        $userA->givePermissionTo('read-all-notifications');
        $responseA = $this->actingAs($userA)->getJson('/api/notifications');
        $this->assertTrue($responseA->json('data.0.viewed'));

        // Check User B doesn't see as viewed
        $userB->givePermissionTo('read-all-notifications');
        $responseB = $this->actingAs($userB)->getJson('/api/notifications');
        $this->assertFalse($responseB->json('data.0.viewed'));
    }

    public function test_batch_store_requires_valid_notification_ids(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('create-viewed-notifications');

        $response = $this->actingAs($user)->postJson('/api/viewed-notifications/batch', [
            'resources' => [
                ['fcm_notification_id' => 99999],
            ]
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('resources.0.fcm_notification_id');
    }

    public function test_batch_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/viewed-notifications/batch', [
            'resources' => [['fcm_notification_id' => 1]]
        ]);

        $response->assertStatus(401);
    }

    public function test_batch_store_requires_permission(): void
    {
        $user = User::factory()->create();
        $notification = FcmNotificationHistory::factory()->create();

        // User doesn't have the permission
        $response = $this->actingAs($user)->postJson('/api/viewed-notifications/batch', [
            'resources' => [['fcm_notification_id' => $notification->id]]
        ]);

        $response->assertStatus(403);
    }

    public function test_batch_store_assigns_current_user_id(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('create-viewed-notifications');
        $notification = FcmNotificationHistory::factory()->create();

        $this->actingAs($user)->postJson('/api/viewed-notifications/batch', [
            'resources' => [['fcm_notification_id' => $notification->id]]
        ])->assertStatus(201);

        $this->assertDatabaseHas('viewed_notifications', [
            'fcm_notification_id' => $notification->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_batch_store_prevents_duplicates(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('create-viewed-notifications');
        $notification = FcmNotificationHistory::factory()->create();

        // First request
        $this->actingAs($user)->postJson('/api/viewed-notifications/batch', [
            'resources' => [['fcm_notification_id' => $notification->id]]
        ])->assertStatus(201);

        // Second request with same data
        $this->actingAs($user)->postJson('/api/viewed-notifications/batch', [
            'resources' => [['fcm_notification_id' => $notification->id]]
        ])->assertStatus(201);

        // Should only have one record
        $this->assertDatabaseCount('viewed_notifications', 1);
    }

    public function test_notification_index_shows_viewed_field(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['read-all-notifications', 'create-viewed-notifications']);
        $notification = FcmNotificationHistory::factory()->create();

        // Mark as viewed
        $this->actingAs($user)->postJson('/api/viewed-notifications/batch', [
            'resources' => [['fcm_notification_id' => $notification->id]]
        ]);

        // Get notifications
        $response = $this->actingAs($user)->getJson('/api/notifications');

        $response->assertStatus(200)
                 ->assertJsonPath('data.0.viewed', true);
    }
}
