<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendBulkNotification;
use App\Http\Requests\Notifications\StoreNotificationRequest;

class NotificationController extends Controller
{
    public function store(StoreNotificationRequest $request)
    {
        $validated = $request->validated();

        $recipients_count = \App\Models\User::role('customer')->count();

        // Despacha el Job para enviar los correos en segundo plano
        SendBulkNotification::dispatch($validated['title'], $validated['message']);

        return response()->json([
            'title' => $validated['title'],
            'message' => $validated['message'],
            'recipients_count' => $recipients_count,
            'created_at' => now()->toISOString(),
        ], 201);
    }
}