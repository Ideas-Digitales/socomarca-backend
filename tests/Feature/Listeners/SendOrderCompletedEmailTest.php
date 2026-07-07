<?php

use App\Events\OrderCompleted;
use App\Listeners\SendOrderCompletedEmail;
use App\Mail\OrderCompletedMail;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Create a fully populated completed-order scenario with all related entities.
 *
 * @return array{
 *     user: User,
 *     branch: Branch,
 *     paymentMethod: PaymentMethod,
 *     order: Order,
 *     product: Product
 * }
 */
function makeCompletedOrderScenario(): array
{
    $user = User::factory()->create();
    $branch = Branch::factory()->create();
    $paymentMethod = PaymentMethod::factory()->create([
        'code' => 'webpay',
        'name' => 'Webpay',
    ]);
    $order = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'branch_id' => $branch->id,
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
        'branch' => $branch,
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

    ['user' => $user, 'order' => $order, 'product' => $product] = makeCompletedOrderScenario();

    (new SendOrderCompletedEmail)->handle(new OrderCompleted($order));

    Mail::assertSent(OrderCompletedMail::class, function (OrderCompletedMail $mail) use ($user, $order, $product) {
        $rendered = $mail->render();
        $logoUrl = config('random.site_logo_url');

        return str_contains($rendered, 'Pedido')
            && str_contains($rendered, (string) $order->id)
            && str_contains($rendered, $user->name)
            && str_contains($rendered, $product->name)
            && str_contains($rendered, $logoUrl);
    });
});

test('logo URL is read from config random site_logo_url', function () {
    Mail::fake();

    ['order' => $order] = makeCompletedOrderScenario();

    (new SendOrderCompletedEmail)->handle(new OrderCompleted($order));

    Mail::assertSent(OrderCompletedMail::class, function (OrderCompletedMail $mail) {
        return str_contains($mail->render(), 'src="'.config('random.site_logo_url').'"');
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
