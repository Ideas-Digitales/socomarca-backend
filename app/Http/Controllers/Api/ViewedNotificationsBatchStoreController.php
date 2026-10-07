<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ViewedNotifications\ViewedNotificationsBatchStoreRequest;
use App\Http\Resources\ViewedNotificationResource;
use App\Models\ViewedNotification;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Notifications')]
class ViewedNotificationsBatchStoreController extends Controller
{
    /**
     * Mark notifications as viewed
     *
     * Marks the given notifications as viewed by the authenticated user. Notifications already marked
     * keep their original date, so repeating the request is safe.
     *
     * Allowed for users with the `create-viewed-notifications` permission.
     */
    public function __invoke(ViewedNotificationsBatchStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()->id;

        $createdNotifications = [];

        foreach ($validated['resources'] as $resource) {
            $viewedNotification = ViewedNotification::firstOrCreate(
                [
                    'fcm_notification_id' => $resource['fcm_notification_id'],
                    'user_id' => $userId,
                ]
            );

            $createdNotifications[] = [
                'id' => $viewedNotification->id,
                'fcm_notification_id' => $viewedNotification->fcm_notification_id,
                /** When the user first marked the notification as viewed. */
                'created_at' => $viewedNotification->created_at->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $createdNotifications,
        ], 201);
    }
}
