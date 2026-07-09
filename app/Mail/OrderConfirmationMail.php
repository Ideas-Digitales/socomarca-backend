<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->order->loadMissing([
            "user",
            "orderDetails.product",
            "branch",
            "payments.paymentMethod",
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "¡Gracias por tu compra! Hemos recibido tu pedido N°{$this->order->id} en SOCOMARCA",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: "emails.order-confirmation",
            with: [
                "order" => $this->order,
            ],
        );
    }
}
