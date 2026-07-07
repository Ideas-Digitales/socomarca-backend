<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Mail\OrderCompletedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderCompletedEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderCompleted $event): void
    {
        $recipient = config('random.warehouse_email_recipient');

        if (empty($recipient)) {
            Log::warning('SendOrderCompletedEmail: WAREHOUSE_EMAIL_RECIPIENT is not configured, skipping email send.', [
                'order_id' => $event->order->id,
            ]);
            return;
        }

        $event->order->loadMissing(['user', 'orderDetails.product', 'branch', 'payments.paymentMethod']);

        Mail::to($recipient)->send(new OrderCompletedMail($event->order));
    }
}
