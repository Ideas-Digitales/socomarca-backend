<?php

use App\Models\CreditLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;

function createBlockedCreditPurchase(User $user, string $status = 'processing', string $methodCode = 'random_credit'): Payment
{
    $paymentMethod = PaymentMethod::firstOrCreate(['code' => $methodCode], ['name' => $methodCode, 'active' => true]);

    return Payment::factory()->create([
        'order_id' => Order::factory()->create(['user_id' => $user->id])->id,
        'payment_method_id' => $paymentMethod->id,
        'status' => $status,
    ]);
}

describe('random:unblock-credit command', function () {
    beforeEach(function () {
        config(['random.credit_sync.manual_unblock_enabled' => true]);
    });

    it('completes the processing credit payments and unblocks the credit lines', function () {
        $user = User::factory()->create();
        $creditLine = CreditLine::factory()->create(['user_id' => $user->id, 'is_blocked' => true]);
        $first = createBlockedCreditPurchase($user);
        $second = createBlockedCreditPurchase($user);

        $this->artisan('random:unblock-credit', ['user' => $user->id, '--force' => true])
            ->assertExitCode(0);

        expect($creditLine->refresh()->isBlocked())->toBeFalse()
            ->and($first->refresh()->status)->toBe('completed')
            ->and($second->refresh()->status)->toBe('completed');
    });

    it('finds the user by email', function () {
        $user = User::factory()->create(['email' => 'customer@example.com']);
        $creditLine = CreditLine::factory()->create(['user_id' => $user->id, 'is_blocked' => true]);

        $this->artisan('random:unblock-credit', ['user' => ' Customer@Example.com ', '--force' => true])
            ->assertExitCode(0);

        expect($creditLine->refresh()->isBlocked())->toBeFalse();
    });

    it('does not touch other users nor other payment methods or statuses', function () {
        $user = User::factory()->create();
        CreditLine::factory()->create(['user_id' => $user->id, 'is_blocked' => true]);
        $webpay = createBlockedCreditPurchase($user, 'processing', 'transbank');
        $failed = createBlockedCreditPurchase($user, 'failed');

        $otherUser = User::factory()->create();
        $otherLine = CreditLine::factory()->create(['user_id' => $otherUser->id, 'is_blocked' => true]);
        $otherPayment = createBlockedCreditPurchase($otherUser);

        $this->artisan('random:unblock-credit', ['user' => $user->id, '--force' => true])
            ->assertExitCode(0);

        expect($webpay->refresh()->status)->toBe('processing')
            ->and($failed->refresh()->status)->toBe('failed')
            ->and($otherLine->refresh()->isBlocked())->toBeTrue()
            ->and($otherPayment->refresh()->status)->toBe('processing');
    });

    it('changes nothing when the confirmation is declined', function () {
        $user = User::factory()->create();
        $creditLine = CreditLine::factory()->create(['user_id' => $user->id, 'is_blocked' => true]);
        $payment = createBlockedCreditPurchase($user);

        $this->artisan('random:unblock-credit', ['user' => $user->id])
            ->expectsConfirmation('Complete these payments and unblock the credit lines?', 'no')
            ->assertExitCode(1);

        expect($creditLine->refresh()->isBlocked())->toBeTrue()
            ->and($payment->refresh()->status)->toBe('processing');
    });

    it('fails when the user does not exist', function () {
        $this->artisan('random:unblock-credit', ['user' => 999999, '--force' => true])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('is disabled unless explicitly enabled', function () {
        config(['random.credit_sync.manual_unblock_enabled' => false]);
        $user = User::factory()->create();
        $creditLine = CreditLine::factory()->create(['user_id' => $user->id, 'is_blocked' => true]);

        $this->artisan('random:unblock-credit', ['user' => $user->id, '--force' => true])
            ->assertExitCode(1);

        expect($creditLine->refresh()->isBlocked())->toBeTrue();
    });
});
