<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StoreNotificationRequest;
use App\Jobs\SendPushNotification;
use App\Models\FcmNotificationHistory;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;



#[Group('Notifications', 'Send push notifications through Firebase Cloud Messaging and track which ones each user has viewed.', weight: 15)]
class NotificationController extends Controller
{

    /**
     * List sent notifications
     *
     * Lists the push notifications sent, newest first. `viewed` tells whether the authenticated user
     * has marked each one as viewed.
     *
     * @response array{
     *     current_page: int,
     *     data: list<array{id: int, user_id: int, title: string, message: string, sent_at: string, viewed: bool}>,
     *     first_page_url: string,
     *     from: int|null,
     *     last_page: int,
     *     last_page_url: string,
     *     links: list<array{url: string|null, label: string, active: bool}>,
     *     next_page_url: string|null,
     *     path: string,
     *     per_page: int,
     *     prev_page_url: string|null,
     *     to: int|null,
     *     total: int,
     * }
     */
    #[QueryParameter('per_page', 'Items per page, limited to 1-100.', type: 'int', default: 20)]
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

    /**
     * Send a push notification
     *
     * Queues a push notification to every active user with an FCM token. The notification is added
     * to the history when the queued job sends it, so it may not be listed right away.
     */
    public function store(StoreNotificationRequest $request)
    {
        $validated = $request->validated();

        $recipients_count = User::whereNotNull('fcm_token')->where('is_active', true)->count();

        // Despacha el Job para enviar las notificaciones push en segundo plano
        SendPushNotification::dispatch($validated['title'], $validated['message'], $request->user()->id);

        return response()->json([
            'title' => $validated['title'],
            'message' => $validated['message'],
            /** Number of active users with an FCM token when the notification was queued. */
            'recipients_count' => $recipients_count,
            /**
             * The `created_at` sent in the request, or the current time.
             *
             * @var string
             */
            'created_at' => $validated['created_at'] ?? now()->toISOString(),
        ], 201);
    }
}
