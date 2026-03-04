<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ViewedNotifications\ViewedNotificationsBatchStoreRequest;
use App\Http\Resources\ViewedNotificationResource;
use App\Models\ViewedNotification;
use Illuminate\Http\JsonResponse;

class ViewedNotificationsBatchStoreController extends Controller
{
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
                'created_at' => $viewedNotification->created_at->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $createdNotifications,
        ], 201);
    }
}
