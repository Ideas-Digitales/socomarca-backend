<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Mail\OrderConfirmationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmationEmail implements ShouldQueue
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
        $event->order->loadMissing([
            "user",
            "orderDetails.product",
            "branch",
            "payments.paymentMethod",
        ]);

        $recipient = $event->order->user?->email;

        if (empty($recipient)) {
            Log::warning(
                "SendOrderConfirmationEmail: order has no customer email, skipping email send.",
                [
                    "order_id" => $event->order->id,
                ],
            );
            return;
        }

        $cc = collect([
            $event->order->branch?->email,
            $event->order->branch?->commercial_email,
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        Mail::to($recipient)
            ->cc($cc)
            ->send(new OrderConfirmationMail($event->order));
    }
}
