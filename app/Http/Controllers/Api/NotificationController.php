<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StoreNotificationRequest;
use App\Jobs\SendPushNotification;
use App\Models\User;

class NotificationController extends Controller
{

    public function store(StoreNotificationRequest $request)
    {
        $validated = $request->validated();

        $recipients_count = User::whereNotNull('fcm_token')->where('is_active', true)->count();

        // Despacha el Job para enviar las notificaciones push en segundo plano
        SendPushNotification::dispatch($validated['title'], $validated['message']);

        return response()->json([
            'title' => $validated['title'],
            'message' => $validated['message'],
            'recipients_count' => $recipients_count,
            'created_at' => $validated['created_at'] ?? now()->toISOString(),
        ], 201);
    }
}