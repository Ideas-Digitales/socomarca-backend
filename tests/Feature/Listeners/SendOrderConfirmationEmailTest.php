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

test("sends the order confirmation email to the customer", function () {
    Mail::fake();

    ["user" => $user, "order" => $order] = makeCompletedOrderScenario();

    (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order));

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

    return collect($mails->first()->to)->pluck("address")->sort()->values()->all();
}

it("sends one email to the commercial and billing emails of the user", function () {
    Mail::fake();

    ["order" => $order, "user" => $user] = makeCompletedOrderScenario();
    $user->update([
        "email" => "compras@cliente.cl",
        "billing_email" => "dte@cliente.cl",
    ]);

    (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order->fresh()));

    expect(sentConfirmationRecipients())->toBe([
        "compras@cliente.cl",
        "dte@cliente.cl",
    ]);
});

it("sends one email to the user and to the branch the order is placed for", function () {
    Mail::fake();

    ["order" => $order, "user" => $user] = makeCompletedOrderScenario();
    $user->update([
        "email" => "compras@cliente.cl",
        "billing_email" => "dte@cliente.cl",
    ]);
    $branch = User::factory()->create([
        "email" => "sucursal@cliente.cl",
        "billing_email" => "dte-sucursal@cliente.cl",
    ]);
    $order->update(["customer_id" => $branch->id]);

    (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order->fresh()));

    expect(sentConfirmationRecipients())->toBe([
        "compras@cliente.cl",
        "dte-sucursal@cliente.cl",
        "dte@cliente.cl",
        "sucursal@cliente.cl",
    ]);
});

it("normalizes the recipients and removes duplicates", function () {
    Mail::fake();

    ["order" => $order, "user" => $user] = makeCompletedOrderScenario();
    $user->update([
        "email" => "compras@cliente.cl",
        "billing_email" => "dte@cliente.cl",
    ]);
    $branch = User::factory()->create([
        "email" => "sucursal@cliente.cl",
        "billing_email" => "dte@cliente.cl",
    ]);
    // Random sourced values may come with spaces or uppercase.
    DB::table("users")->where("id", $branch->id)->update(["billing_email" => " DTE@Cliente.cl "]);
    $order->update(["customer_id" => $branch->id]);

    (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order->fresh()));

    expect(sentConfirmationRecipients())->toBe([
        "compras@cliente.cl",
        "dte@cliente.cl",
        "sucursal@cliente.cl",
    ]);
});

it("discards empty and invalid recipients", function () {
    Mail::fake();

    ["order" => $order, "user" => $user] = makeCompletedOrderScenario();
    $user->update([
        "email" => "compras@cliente.cl",
        "billing_email" => "no-es-un-email",
    ]);

    (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order->fresh()));

    expect(sentConfirmationRecipients())->toBe(["compras@cliente.cl"]);
});

test("does not send email when customer has no email", function () {
    Mail::fake();

    ["order" => $order, "user" => $user] = makeCompletedOrderScenario();
    $user->update(["email" => "", "billing_email" => null]);
    $order->refresh();

    (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order));

    Mail::assertNothingSent();
});

test(
    "confirmation email subject contains order number and confirmation wording",
    function () {
        Mail::fake();

        ["order" => $order] = makeCompletedOrderScenario();

        (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order));

        Mail::assertSent(OrderConfirmationMail::class, function (
            OrderConfirmationMail $mail,
        ) use ($order) {
            $subject = $mail->envelope()->subject;

            return str_contains($subject, (string) $order->id) &&
                str_contains($subject, "Gracias por tu compra") &&
                str_contains($subject, "SOCOMARCA");
        });
    },
);

test("confirmation email body renders the order summary view", function () {
    Mail::fake();
    Storage::fake('s3');

    [
        "user" => $user,
        "order" => $order,
        "product" => $product,
    ] = makeCompletedOrderScenario();

    (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order));

    Mail::assertSent(OrderConfirmationMail::class, function (
        OrderConfirmationMail $mail,
    ) use ($user, $order, $product) {
        $rendered = $mail->render();
        $logoUrl = Storage::disk('s3')->url('assets/logo.png');

        return str_contains($rendered, "Pedido") &&
            str_contains($rendered, (string) $order->id) &&
            str_contains($rendered, $user->name) &&
            str_contains($rendered, $product->name) &&
            str_contains($rendered, $logoUrl);
    });
});

test("confirmation listener implements ShouldQueue", function () {
    expect(new SendOrderConfirmationEmail())->toBeInstanceOf(
        ShouldQueue::class,
    );
});

test(
    "event dispatch sends both the warehouse email and the customer confirmation email",
    function () {
        Mail::fake();

        ["order" => $order] = makeCompletedOrderScenario();

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
