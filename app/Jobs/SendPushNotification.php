<?php

namespace App\Jobs;

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

    public function __construct($title, $message)
    {
        $this->title = $title;
        $this->message = $message;
    }

    public function handle()
    {
        $users = User::whereNotNull('fcm_token')->where('is_active', true)->get();

        foreach ($users as $user) {
            $user->notify(new PushNotification($this->title, $this->message));
        }
    }
}