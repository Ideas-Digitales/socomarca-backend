<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StoreNotificationRequest;
use App\Jobs\SendPushNotification;
use App\Models\FcmNotificationHistory;
use App\Models\User;



class NotificationController extends Controller
{

    public function index()
    {
        $history = FcmNotificationHistory::orderByDesc('sent_at')
            ->paginate(20);

        return response()->json($history);
    }

    public function store(StoreNotificationRequest $request)
    {
        $validated = $request->validated();

        $recipients_count = User::whereNotNull('fcm_token')->where('is_active', true)->count();

        // Despacha el Job para enviar las notificaciones push en segundo plano
        SendPushNotification::dispatch($validated['title'], $validated['message'], $request->user()->id);

        return response()->json([
            'title' => $validated['title'],
            'message' => $validated['message'],
            'recipients_count' => $recipients_count,
            'created_at' => $validated['created_at'] ?? now()->toISOString(),
        ], 201);
    }
}