<?php

use App\Events\OrderCanceled;
use App\Listeners\SendOrderCancellationEmail;
use App\Mail\OrderCancellationMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * @param  array<string, mixed>  $contact
 * @return array<string, mixed>
 */
function orderMetaForCancellation(Order $order, array $contact): array
{
    $meta = $order->order_meta;

    if (is_string($meta)) {
        $decoded = json_decode($meta, true);
        $meta = is_array($decoded) ? $decoded : [];
    }

    return array_merge($meta ?? [], ['contact' => $contact]);
}

/**
 * @return list<string>
 */
function cancellationRecipients(): array
{
    $mails = Mail::sent(OrderCancellationMail::class);
    expect($mails)->toHaveCount(1);

    return collect($mails->first()->to)->pluck('address')->sort()->values()->all();
}

function notifyCancellation(Order $order): void
{
    (new SendOrderCancellationEmail)->handle(new OrderCanceled($order));
}

it('sends one cancellation email to the branch that placed the order and to the system inbox', function () {
    Mail::fake();

    $buyer = User::factory()->create([
        'email' => 'compras@cliente.cl',
        'billing_email' => 'dte@cliente.cl',
    ]);
    $order = Order::factory()->create([
        'user_id' => $buyer->id,
        'customer_id' => $buyer->id,
        'status' => 'canceled',
    ]);

    notifyCancellation($order->fresh());

    expect(cancellationRecipients())->toEqualCanonicalizing([
        'compras@cliente.cl',
        'dte@cliente.cl',
        config('random.warehouse_email_recipient'),
    ]);
});

it('also notifies the secondary branch when a primary branch placed the order for it', function () {
    Mail::fake();

    $buyer = User::factory()->create([
        'name' => 'Sucursal Principal',
        'email' => 'compras@cliente.cl',
        'billing_email' => 'dte@cliente.cl',
    ]);
    $branch = User::factory()->create([
        'name' => 'Sucursal Norte',
        'email' => 'sucursal@cliente.cl',
        'billing_email' => 'dte-sucursal@cliente.cl',
    ]);
    $order = Order::factory()->create([
        'user_id' => $buyer->id,
        'customer_id' => $branch->id,
        'status' => 'canceled',
        'amount' => 1190,
    ]);
    $product = Product::factory()->create(['name' => 'Arroz grado 2']);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit' => 'UN',
    ]);

    notifyCancellation($order->fresh());

    expect(cancellationRecipients())->toEqualCanonicalizing([
        'compras@cliente.cl',
        'dte-sucursal@cliente.cl',
        'dte@cliente.cl',
        'sucursal@cliente.cl',
        config('random.warehouse_email_recipient'),
    ]);

    $mail = Mail::sent(OrderCancellationMail::class)->first();
    $body = $mail->render();

    expect($mail->envelope()->subject)->toBe("Se canceló el pedido N°{$order->id} en SOCOMARCA")
        ->and($body)->toContain('fue cancelado')
        ->and($body)->toContain('Sucursal Principal')
        ->and($body)->toContain('Sucursal Norte')
        ->and($body)->toContain('Arroz grado 2')
        ->and($body)->toContain((string) $order->id);
});

it('includes the contact email when one was selected', function () {
    Mail::fake();

    $buyer = User::factory()->create([
        'email' => 'compras@cliente.cl',
        'billing_email' => null,
    ]);
    $order = Order::factory()->create([
        'user_id' => $buyer->id,
        'customer_id' => $buyer->id,
        'status' => 'canceled',
    ]);
    $order->update([
        'order_meta' => orderMetaForCancellation($order, [
            'name' => 'Ana Pérez',
            'email' => 'Ana@Contacto.cl',
        ]),
    ]);

    notifyCancellation($order->fresh());

    expect(cancellationRecipients())->toContain('ana@contacto.cl');

    $body = Mail::sent(OrderCancellationMail::class)->first()->render();
    expect($body)->toContain('Ana Pérez');
});

it('does not repeat an address that already receives the cancellation', function () {
    Mail::fake();

    $buyer = User::factory()->create([
        'email' => 'compras@cliente.cl',
        'billing_email' => null,
    ]);
    $order = Order::factory()->create([
        'user_id' => $buyer->id,
        'customer_id' => $buyer->id,
        'status' => 'canceled',
    ]);
    $order->update([
        'order_meta' => orderMetaForCancellation($order, [
            'name' => 'Ana Pérez',
            'email' => 'compras@cliente.cl',
        ]),
    ]);

    notifyCancellation($order->fresh());

    expect(cancellationRecipients())->toEqualCanonicalizing([
        'compras@cliente.cl',
        config('random.warehouse_email_recipient'),
    ]);
});

it('skips a contact email that cannot be notified and a billing mailbox of an excluded domain', function () {
    Mail::fake();

    $buyer = User::factory()->create([
        'email' => 'compras@cliente.cl',
        'billing_email' => 'FacturacionMIPYME@SII.cl',
    ]);
    $order = Order::factory()->create([
        'user_id' => $buyer->id,
        'customer_id' => $buyer->id,
        'status' => 'canceled',
    ]);
    $order->update([
        'order_meta' => orderMetaForCancellation($order, [
            'name' => 'Ana Pérez',
            'email' => 'no-es-un-correo',
        ]),
    ]);

    notifyCancellation($order->fresh());

    expect(cancellationRecipients())->toEqualCanonicalizing([
        'compras@cliente.cl',
        config('random.warehouse_email_recipient'),
    ]);
});

it('still notifies the branch when the system inbox is not configured', function () {
    Mail::fake();
    Log::spy();
    config()->set('random.warehouse_email_recipient', '');

    $buyer = User::factory()->create([
        'email' => 'compras@cliente.cl',
        'billing_email' => null,
    ]);
    $order = Order::factory()->create([
        'user_id' => $buyer->id,
        'customer_id' => $buyer->id,
        'status' => 'canceled',
    ]);

    notifyCancellation($order->fresh());

    expect(cancellationRecipients())->toBe(['compras@cliente.cl']);
    Log::shouldHaveReceived('warning')->once();
});

it('sends nothing when the order has no notifiable address', function () {
    Mail::fake();
    Log::spy();
    config()->set('random.warehouse_email_recipient', null);

    $buyer = User::factory()->create([
        'email' => 'no-es-un-correo',
        'billing_email' => null,
    ]);
    $order = Order::factory()->create([
        'user_id' => $buyer->id,
        'customer_id' => $buyer->id,
        'status' => 'canceled',
    ]);

    notifyCancellation($order->fresh());

    Mail::assertNothingSent();
});

it('queues the cancellation listener on the email queue', function () {
    $listener = new SendOrderCancellationEmail;

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->queue)->toBe('email');
});

it('sends the cancellation email when the order canceled event is dispatched', function () {
    Mail::fake();

    $buyer = User::factory()->create([
        'email' => 'compras@cliente.cl',
        'billing_email' => null,
    ]);
    $order = Order::factory()->create([
        'user_id' => $buyer->id,
        'customer_id' => $buyer->id,
        'status' => 'canceled',
    ]);

    OrderCanceled::dispatch($order);

    Mail::assertSent(OrderCancellationMail::class, fn (OrderCancellationMail $mail) => $mail->order->id === $order->id
        && $mail->hasTo('compras@cliente.cl')
        && $mail->hasTo(config('random.warehouse_email_recipient')));
});
