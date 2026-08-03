<?php

use App\Events\OrderCompleted;
use App\Listeners\SendOrderConfirmationEmail;
use App\Mail\OrderCompletedMail;
use App\Mail\OrderConfirmationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
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

test("CCs both branch emails when order has a branch", function () {
    Mail::fake();

    ["branch" => $branch, "order" => $order] = makeCompletedOrderScenario();

    (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order));

    Mail::assertSent(OrderConfirmationMail::class, function (
        OrderConfirmationMail $mail,
    ) use ($branch) {
        return $mail->hasCc($branch->email) &&
            $mail->hasCc($branch->commercial_email);
    });
});

test("does not CC when order has no branch", function () {
    Mail::fake();

    ["order" => $order] = makeCompletedOrderScenario();
    $order->update(["branch_id" => null]);
    $order->refresh();

    (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order));

    Mail::assertSent(OrderConfirmationMail::class, function (
        OrderConfirmationMail $mail,
    ) {
        return $mail->cc === [];
    });
});

test(
    "dedupes CC when branch email and commercial_email are equal",
    function () {
        Mail::fake();

        ["order" => $order, "branch" => $branch] = makeCompletedOrderScenario();
        $branch->update(["commercial_email" => $branch->email]);
        $order->refresh();

        (new SendOrderConfirmationEmail())->handle(new OrderCompleted($order));

        Mail::assertSent(OrderConfirmationMail::class, function (
            OrderConfirmationMail $mail,
        ) {
            return count($mail->cc) === 1;
        });
    },
);

test("does not send email when customer has no email", function () {
    Mail::fake();

    ["order" => $order, "user" => $user] = makeCompletedOrderScenario();
    $user->update(["email" => ""]);
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
