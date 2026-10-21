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

    /** Purchase placed for the buyer's own branch. */
    public const AUDIENCE_SELF = 'self';

    /** Primary branch that placed the purchase for a secondary branch. */
    public const AUDIENCE_BUYER = 'buyer';

    /** Secondary branch the purchase was placed for. */
    public const AUDIENCE_BRANCH = 'branch';

    /** Contact person named on the order. */
    public const AUDIENCE_CONTACT = 'contact';

    public function __construct(
        public Order $order,
        public string $audience = self::AUDIENCE_SELF,
    ) {
        $this->order->loadMissing([
            'user',
            'customer',
            'contact',
            'orderDetails.product',
            'payments.paymentMethod',
            'randomDocuments',
        ]);
    }

    public function envelope(): Envelope
    {
        $id = $this->order->id;
        $buyerName = $this->order->user?->name ?? 'Cliente';
        $customerName = $this->order->customer?->name ?? $buyerName;

        $subject = match ($this->audience) {
            self::AUDIENCE_BUYER => "Registramos tu compra para la sucursal {$customerName}. Pedido N°{$id} en SOCOMARCA",
            self::AUDIENCE_BRANCH => "{$buyerName} registró una compra para tu sucursal. Pedido N°{$id} en SOCOMARCA",
            self::AUDIENCE_CONTACT => "Te indicaron como contacto del pedido N°{$id} en SOCOMARCA",
            default => "¡Gracias por tu compra! Hemos recibido tu pedido N°{$id} en SOCOMARCA",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-confirmation',
            with: [
                'order' => $this->order,
                'audience' => $this->audience,
            ],
        );
    }
}
