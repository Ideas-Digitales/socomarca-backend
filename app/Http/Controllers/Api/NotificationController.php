<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StoreNotificationRequest;
use App\Jobs\SendPushNotification;
use App\Models\FcmNotificationHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;



class NotificationController extends Controller
{

    /**
     * List the notification history with a 'viewed' flag for the current user.
     *
     * @param Request $request Accepts an optional 'per_page' (1-100, defaults to 20)
     */
    public function index(Request $request)
    {
        $userId = Auth::user()->id;
        // The clients ask for a page size; until now it was ignored and they always
        // got 20 rows back.
        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $history = FcmNotificationHistory::select(
            'fcm_notification_histories.*',
            DB::raw('CASE WHEN viewed_notifications.id IS NOT NULL THEN true ELSE false END as viewed')
        )
            ->leftJoin('viewed_notifications', function ($join) use ($userId) {
                $join->on('fcm_notification_histories.id', '=', 'viewed_notifications.fcm_notification_id')
                     ->where('viewed_notifications.user_id', '=', $userId);
            })
            ->orderByDesc('sent_at')
            ->paginate($perPage);

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