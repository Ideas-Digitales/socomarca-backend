<?php

use App\Events\OrderCompleted;
use App\Listeners\SendOrderCompletedEmail;
use App\Mail\OrderCompletedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Create a fully populated completed-order scenario with all related entities.
 *
 * The order is placed by the user for itself.
 *
 * @return array{
 *     user: User,
 *     paymentMethod: PaymentMethod,
 *     order: Order,
 *     product: Product
 * }
 */
function makeCompletedOrderScenario(): array
{
    $user = User::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create([
        'code' => 'webpay',
        'name' => 'Webpay',
    ]);
    $order = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'amount' => 55000,
        'subtotal' => 45000,
        'shipping_cost' => 10000,
    ]);
    $product = Product::factory()->create(['name' => 'Sample product']);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 25000,
        'unit' => 'kg',
    ]);
    Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method_id' => $paymentMethod->id,
        'response_status' => 'AUTHORIZED',
    ]);

    return [
        'user' => $user,
        'paymentMethod' => $paymentMethod,
        'order' => $order,
        'product' => $product,
    ];
}

test('sends the order completed email to the warehouse recipient', function () {
    Mail::fake();

    ['order' => $order] = makeCompletedOrderScenario();
    $recipient = config('random.warehouse_email_recipient');
    expect($recipient)->not->toBeEmpty();

    (new SendOrderCompletedEmail)->handle(new OrderCompleted($order));

    Mail::assertSent(OrderCompletedMail::class, function (OrderCompletedMail $mail) use ($recipient, $order) {
        return $mail->hasTo($recipient)
            && $mail->order->id === $order->id;
    });
});

test('email subject contains customer name and order number', function () {
    Mail::fake();

    ['user' => $user, 'order' => $order] = makeCompletedOrderScenario();

    (new SendOrderCompletedEmail)->handle(new OrderCompleted($order));

    Mail::assertSent(OrderCompletedMail::class, function (OrderCompletedMail $mail) use ($user, $order) {
        $subject = $mail->envelope()->subject;

        return str_contains($subject, $user->name)
            && str_contains($subject, (string) $order->id)
            && str_contains($subject, 'SOCOMARCA');
    });
});

test('email body renders the order summary view', function () {
    Mail::fake();
    Storage::fake('s3');

    ['user' => $user, 'order' => $order, 'product' => $product] = makeCompletedOrderScenario();

    (new SendOrderCompletedEmail)->handle(new OrderCompleted($order));

    Mail::assertSent(OrderCompletedMail::class, function (OrderCompletedMail $mail) use ($user, $order, $product) {
        $rendered = $mail->render();
        $logoUrl = Storage::disk('s3')->url('assets/logo.png');

        return str_contains($rendered, 'Pedido')
            && str_contains($rendered, (string) $order->id)
            && str_contains($rendered, $user->name)
            && str_contains($rendered, $product->name)
            && str_contains($rendered, $logoUrl);
    });
});

it('renders the branch the order is placed for', function () {
    Mail::fake();
    Storage::fake('s3');

    ['user' => $user, 'order' => $order] = makeCompletedOrderScenario();
    $branch = User::factory()->create([
        'name' => 'Sucursal Los Leones',
        'user_code' => '77528378',
        'branch_code' => 'LO',
    ]);
    $order->update(['customer_id' => $branch->id]);

    (new SendOrderCompletedEmail)->handle(new OrderCompleted($order->fresh()));

    Mail::assertSent(OrderCompletedMail::class, function (OrderCompletedMail $mail) use ($user) {
        $rendered = $mail->render();

        return str_contains($rendered, 'Sucursal de destino')
            && str_contains($rendered, 'Sucursal Los Leones')
            && str_contains($rendered, '77528378 / LO')
            && str_contains($rendered, $user->name);
    });
});

test('logo URL is read from the s3 disk', function () {
    Mail::fake();
    Storage::fake('s3');

    ['order' => $order] = makeCompletedOrderScenario();

    (new SendOrderCompletedEmail)->handle(new OrderCompleted($order));

    Mail::assertSent(OrderCompletedMail::class, function (OrderCompletedMail $mail) {
        return str_contains($mail->render(), 'src="'.Storage::disk('s3')->url('assets/logo.png').'"');
    });
});

test('does not send email when warehouse recipient is not configured', function () {
    Mail::fake();
    config()->set('random.warehouse_email_recipient', null);

    ['order' => $order] = makeCompletedOrderScenario();

    (new SendOrderCompletedEmail)->handle(new OrderCompleted($order));

    Mail::assertNothingSent();
});

test('does not send email when warehouse recipient is empty string', function () {
    Mail::fake();
    config()->set('random.warehouse_email_recipient', '');

    ['order' => $order] = makeCompletedOrderScenario();

    (new SendOrderCompletedEmail)->handle(new OrderCompleted($order));

    Mail::assertNothingSent();
});

test('listener implements ShouldQueue', function () {
    expect(new SendOrderCompletedEmail)->toBeInstanceOf(ShouldQueue::class);
});

test('listener handles event dispatched via the event system', function () {
    Mail::fake();

    ['order' => $order] = makeCompletedOrderScenario();

    OrderCompleted::dispatch($order);

    Mail::assertSent(OrderCompletedMail::class, function (OrderCompletedMail $mail) use ($order) {
        return $mail->order->id === $order->id;
    });
});
