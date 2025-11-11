<?php

namespace App\Jobs;

use App\Models\FcmNotificationHistory;
use App\Models\User;
use App\Notifications\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $title;
    public $message;
    public $sender_id;

    public function __construct($title, $message, $sender_id)
    {
        $this->title = $title;
        $this->message = $message;
        $this->sender_id = $sender_id;
    }

    public function handle()
    {
        $users = User::whereNotNull('fcm_token')->where('is_active', true)->get();

        FcmNotificationHistory::create([
            'user_id' => $this->sender_id,
            'title' => $this->title,
            'message' => $this->message,
            'sent_at' => now(),
        ]);

        foreach ($users as $user) {
            $user->notify(new PushNotification($this->title, $this->message));
        }
    }
}