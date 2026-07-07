<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order
    ) {
        $this->order->loadMissing(['user', 'orderDetails.product', 'branch', 'payments.paymentMethod']);
    }

    public function envelope(): Envelope
    {
        $customerName = $this->order->user?->name ?? 'Cliente';

        return new Envelope(
            subject: "{$customerName} ha registrado la compra N°{$this->order->id} en SOCOMARCA",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-completed',
            with: [
                'order' => $this->order,
            ],
        );
    }
}
