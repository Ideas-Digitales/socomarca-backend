<?php

use App\Events\OrderCompleted;
use App\Listeners\SendOrderConfirmationEmail;
use App\Mail\OrderCompletedMail;
use App\Mail\OrderConfirmationMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

test('sends the order confirmation email to the customer', function () {
    Mail::fake();

    ['user' => $user, 'order' => $order] = makeCompletedOrderScenario();

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order));

    Mail::assertSent(OrderConfirmationMail::class, function (
        OrderConfirmationMail $mail,
    ) use ($user, $order) {
        return $mail->hasTo($user->email) && $mail->order->id === $order->id;
    });
});

/**
 * Recipients (To) of the single confirmation email sent.
 *
 * @return list<string>
 */
function sentConfirmationRecipients(): array
{
    $mails = Mail::sent(OrderConfirmationMail::class);
    expect($mails)->toHaveCount(1);

    return confirmationRecipients($mails->first());
}

/**
 * @return list<string>
 */
function confirmationRecipients(OrderConfirmationMail $mail): array
{
    return collect($mail->to)->pluck('address')->sort()->values()->all();
}

it('sends one email to the commercial and billing emails of the user', function () {
    Mail::fake();

    ['order' => $order, 'user' => $user] = makeCompletedOrderScenario();
    $user->update([
        'email' => 'compras@cliente.cl',
        'billing_email' => 'dte@cliente.cl',
    ]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    expect(sentConfirmationRecipients())->toBe([
        'compras@cliente.cl',
        'dte@cliente.cl',
    ]);
});

it('sends the buyer and the branch their own confirmation when the order is placed for another branch', function () {
    Mail::fake();
    Storage::fake('s3');

    ['order' => $order, 'user' => $user] = makeCompletedOrderScenario();
    $user->update([
        'name' => 'Sucursal Principal',
        'email' => 'compras@cliente.cl',
        'billing_email' => 'dte@cliente.cl',
    ]);
    $branch = User::factory()->create([
        'name' => 'Sucursal Norte',
        'email' => 'sucursal@cliente.cl',
        'billing_email' => 'dte-sucursal@cliente.cl',
    ]);
    $order->update(['customer_id' => $branch->id]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    $mails = Mail::sent(OrderConfirmationMail::class);
    expect($mails)->toHaveCount(2);

    $buyerMail = $mails->first(
        fn (OrderConfirmationMail $mail) => $mail->audience === OrderConfirmationMail::AUDIENCE_BUYER,
    );
    $branchMail = $mails->first(
        fn (OrderConfirmationMail $mail) => $mail->audience === OrderConfirmationMail::AUDIENCE_BRANCH,
    );

    expect($buyerMail)->not->toBeNull()
        ->and(confirmationRecipients($buyerMail))->toBe([
            'compras@cliente.cl',
            'dte@cliente.cl',
        ])
        ->and($branchMail)->not->toBeNull()
        ->and(confirmationRecipients($branchMail))->toBe([
            'dte-sucursal@cliente.cl',
            'sucursal@cliente.cl',
        ]);

    $buyerSubject = $buyerMail->envelope()->subject;
    $buyerBody = $buyerMail->render();
    expect($buyerSubject)->toContain('Sucursal Norte')
        ->and($buyerSubject)->toContain((string) $order->id)
        ->and($buyerSubject)->toContain('SOCOMARCA')
        ->and($buyerBody)->toContain('Registramos tu compra para la sucursal')
        ->and($buyerBody)->toContain('Sucursal Norte')
        ->and($buyerBody)->not->toContain('¡Gracias por tu compra');

    $branchSubject = $branchMail->envelope()->subject;
    $branchBody = $branchMail->render();
    expect($branchSubject)->toContain('Sucursal Principal')
        ->and($branchSubject)->toContain((string) $order->id)
        ->and($branchSubject)->toContain('SOCOMARCA')
        ->and($branchBody)->toContain('Sucursal Principal')
        ->and($branchBody)->toContain('registró una compra para tu sucursal')
        ->and($branchBody)->not->toContain('¡Gracias por tu compra');
});

it('normalizes the recipients and keeps a shared address only on the buyer email', function () {
    Mail::fake();

    ['order' => $order, 'user' => $user] = makeCompletedOrderScenario();
    $user->update([
        'email' => 'compras@cliente.cl',
        'billing_email' => 'dte@cliente.cl',
    ]);
    $branch = User::factory()->create([
        'email' => 'sucursal@cliente.cl',
        'billing_email' => 'dte@cliente.cl',
    ]);
    // Random sourced values may come with spaces or uppercase.
    DB::table('users')->where('id', $branch->id)->update(['billing_email' => ' DTE@Cliente.cl ']);
    $order->update(['customer_id' => $branch->id]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    $mails = Mail::sent(OrderConfirmationMail::class);
    expect($mails)->toHaveCount(2);

    $buyerMail = $mails->first(
        fn (OrderConfirmationMail $mail) => $mail->audience === OrderConfirmationMail::AUDIENCE_BUYER,
    );
    $branchMail = $mails->first(
        fn (OrderConfirmationMail $mail) => $mail->audience === OrderConfirmationMail::AUDIENCE_BRANCH,
    );

    expect(confirmationRecipients($buyerMail))->toBe([
        'compras@cliente.cl',
        'dte@cliente.cl',
    ])->and(confirmationRecipients($branchMail))->toBe([
        'sucursal@cliente.cl',
    ]);
});

it('does not send a second email when every branch address already belongs to the buyer', function () {
    Mail::fake();

    ['order' => $order, 'user' => $user] = makeCompletedOrderScenario();
    $user->update([
        'email' => 'compras@cliente.cl',
        'billing_email' => 'dte@cliente.cl',
    ]);
    $branch = User::factory()->create([
        'email' => 'compras@cliente.cl',
        'billing_email' => 'dte@cliente.cl',
    ]);
    $order->update(['customer_id' => $branch->id]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    $mails = Mail::sent(OrderConfirmationMail::class);
    expect($mails)->toHaveCount(1)
        ->and($mails->first()->audience)->toBe(OrderConfirmationMail::AUDIENCE_BUYER)
        ->and(confirmationRecipients($mails->first()))->toBe([
            'compras@cliente.cl',
            'dte@cliente.cl',
        ]);
});

it('sends only the branch email when the buyer has no valid address', function () {
    Mail::fake();

    ['order' => $order, 'user' => $user] = makeCompletedOrderScenario();
    $user->update([
        'email' => 'no-es-un-email',
        'billing_email' => null,
    ]);
    $branch = User::factory()->create([
        'email' => 'sucursal@cliente.cl',
        'billing_email' => 'FacturacionMIPYME@SII.cl',
    ]);
    $order->update(['customer_id' => $branch->id]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    $mails = Mail::sent(OrderConfirmationMail::class);
    expect($mails)->toHaveCount(1)
        ->and($mails->first()->audience)->toBe(OrderConfirmationMail::AUDIENCE_BRANCH)
        ->and(confirmationRecipients($mails->first()))->toBe([
            'sucursal@cliente.cl',
        ]);
});

it('discards empty and invalid recipients', function () {
    Mail::fake();

    ['order' => $order, 'user' => $user] = makeCompletedOrderScenario();
    $user->update([
        'email' => 'compras@cliente.cl',
        'billing_email' => 'no-es-un-email',
    ]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    expect(sentConfirmationRecipients())->toBe(['compras@cliente.cl']);
});

it('discards the recipients of an excluded domain', function () {
    Mail::fake();

    ['order' => $order, 'user' => $user] = makeCompletedOrderScenario();
    $user->update([
        'email' => 'compras@cliente.cl',
        'billing_email' => 'FacturacionMIPYME@SII.cl',
    ]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    expect(sentConfirmationRecipients())->toBe(['compras@cliente.cl']);
});

test('does not send email when customer has no email', function () {
    Mail::fake();

    ['order' => $order, 'user' => $user] = makeCompletedOrderScenario();
    $user->update(['email' => '', 'billing_email' => null]);
    $order->refresh();

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order));

    Mail::assertNothingSent();
});

test(
    'confirmation email subject contains order number and confirmation wording',
    function () {
        Mail::fake();

        ['order' => $order] = makeCompletedOrderScenario();

        (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order));

        Mail::assertSent(OrderConfirmationMail::class, function (
            OrderConfirmationMail $mail,
        ) use ($order) {
            $subject = $mail->envelope()->subject;

            return str_contains($subject, (string) $order->id) &&
                str_contains($subject, 'Gracias por tu compra') &&
                str_contains($subject, 'SOCOMARCA');
        });
    },
);

test('confirmation email body renders the order summary view', function () {
    Mail::fake();
    Storage::fake('s3');

    [
        'user' => $user,
        'order' => $order,
        'product' => $product,
    ] = makeCompletedOrderScenario();

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order));

    Mail::assertSent(OrderConfirmationMail::class, function (
        OrderConfirmationMail $mail,
    ) use ($user, $order, $product) {
        $rendered = $mail->render();
        $logoUrl = Storage::disk('s3')->url('assets/logo.png');

        return str_contains($rendered, '¡Gracias por tu compra') &&
            str_contains($rendered, 'Pedido') &&
            str_contains($rendered, (string) $order->id) &&
            str_contains($rendered, $user->name) &&
            str_contains($rendered, $product->name) &&
            str_contains($rendered, $logoUrl) &&
            ! str_contains($rendered, 'para la sucursal') &&
            ! str_contains($rendered, 'para tu sucursal');
    });
});

/**
 * The order factory stores order_meta already encoded, so the cast may expose a string.
 *
 * @param  array<string, mixed>  $contact
 * @return array<string, mixed>
 */
function orderMetaWithContact(\App\Models\Order $order, array $contact): array
{
    $meta = $order->order_meta;

    if (is_string($meta)) {
        $decoded = json_decode($meta, true);
        $meta = is_array($decoded) ? $decoded : [];
    }

    return array_merge($meta ?? [], ['contact' => $contact]);
}

it('sends the order information to the contact email', function () {
    Mail::fake();
    Storage::fake('s3');

    ['order' => $order, 'product' => $product] = makeCompletedOrderScenario();
    $order->update([
        'order_meta' => orderMetaWithContact($order, [
            'name' => 'Ana Pérez',
            'email' => 'Ana@Contacto.cl',
        ]),
    ]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    $mails = Mail::sent(OrderConfirmationMail::class);
    $contactMail = $mails->first(
        fn (OrderConfirmationMail $mail) => $mail->audience === OrderConfirmationMail::AUDIENCE_CONTACT,
    );

    expect($contactMail)->not->toBeNull()
        ->and(confirmationRecipients($contactMail))->toBe(['ana@contacto.cl']);

    $rendered = $contactMail->render();
    expect($contactMail->envelope()->subject)->toContain('contacto')
        ->and($contactMail->envelope()->subject)->toContain((string) $order->id)
        ->and($rendered)->toContain('Ana Pérez')
        ->and($rendered)->toContain('información de la compra')
        ->and($rendered)->toContain($product->name)
        ->and($rendered)->toContain((string) $order->id);
});

it('does not send a second copy when the contact email already receives the confirmation', function () {
    Mail::fake();

    ['order' => $order, 'user' => $user] = makeCompletedOrderScenario();
    $order->update([
        'order_meta' => orderMetaWithContact($order, [
            'name' => 'Ana Pérez',
            'email' => $user->email,
        ]),
    ]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    expect(Mail::sent(OrderConfirmationMail::class))->toHaveCount(1)
        ->and(Mail::sent(OrderConfirmationMail::class)->first()->audience)
        ->toBe(OrderConfirmationMail::AUDIENCE_SELF);
});

it('skips a contact email that cannot be notified', function () {
    Mail::fake();

    ['order' => $order] = makeCompletedOrderScenario();
    $order->update([
        'order_meta' => orderMetaWithContact($order, [
            'name' => 'Ana Pérez',
            'email' => 'no-es-un-correo',
        ]),
    ]);

    (new SendOrderConfirmationEmail)->handle(new OrderCompleted($order->fresh()));

    expect(Mail::sent(OrderConfirmationMail::class))->toHaveCount(1)
        ->and(Mail::sent(OrderConfirmationMail::class)->first()->audience)
        ->toBe(OrderConfirmationMail::AUDIENCE_SELF);
});

test('confirmation listener implements ShouldQueue', function () {
    expect(new SendOrderConfirmationEmail)->toBeInstanceOf(
        ShouldQueue::class,
    );
});

test(
    'event dispatch sends both the warehouse email and the customer confirmation email',
    function () {
        Mail::fake();

        ['order' => $order] = makeCompletedOrderScenario();

        OrderCompleted::dispatch($order);

        Mail::assertSent(OrderCompletedMail::class, function (
            OrderCompletedMail $mail,
        ) use ($order) {
            return $mail->order->id === $order->id;
        });

        Mail::assertSent(OrderConfirmationMail::class, function (
            OrderConfirmationMail $mail,
        ) use ($order) {
            return $mail->order->id === $order->id;
        });
    },
);
