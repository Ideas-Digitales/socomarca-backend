<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCancellationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order
    ) {
        $this->order->loadMissing([
            'user',
            'customer',
            'contact',
            'orderDetails.product',
            'randomDocuments',
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Se canceló el pedido N°{$this->order->id} en SOCOMARCA",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-cancellation',
            with: [
                'order' => $this->order,
            ],
        );
    }
}
