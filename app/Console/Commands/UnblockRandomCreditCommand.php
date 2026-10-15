<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UnblockRandomCreditCommand extends Command
{
    protected $signature = 'random:unblock-credit
        {user : ID or email of the user that owns the credit line}
        {--force : Skip the confirmation}';

    protected $description = 'QA only: complete the processing credit payments of a user and unblock its credit lines';

    public function handle(): int
    {
        if (!config('random.credit_sync.manual_unblock_enabled')) {
            $this->error('Manual credit unblock is disabled. Set RANDOM_CREDIT_MANUAL_UNBLOCK_ENABLED=true (QA only).');
            return self::FAILURE;
        }

        $identifier = (string) $this->argument('user');
        $user = ctype_digit($identifier)
            ? User::find((int) $identifier)
            : User::where('email', User::normalizeEmail($identifier))->first();

        if (!$user) {
            $this->error("User {$identifier} not found.");
            return self::FAILURE;
        }

        $blockedLines = $user->creditLines()->where('is_blocked', true)->get();

        // Every processing credit payment is completed, not only the one that blocked the line:
        // a leftover one would be picked by ProcessPendingCreditPaymentJob after the next purchase.
        $payments = Payment::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', $user->id))
            ->whereHas('paymentMethod', fn ($query) => $query->where('code', 'random_credit'))
            ->where('status', 'processing')
            ->get();

        if ($blockedLines->isEmpty() && $payments->isEmpty()) {
            $this->info("User {$user->email} has no blocked credit lines nor processing credit payments.");
            return self::SUCCESS;
        }

        $this->table(
            ['Credit line', 'Branch'],
            $blockedLines->map(fn ($line) => [$line->id, $line->branch_code])->all(),
        );
        $this->table(
            ['Payment', 'Order', 'Amount', 'Paid at'],
            $payments->map(fn ($payment) => [
                $payment->id,
                $payment->order_id,
                $payment->amount,
                $payment->paid_at,
            ])->all(),
        );

        if (!$this->option('force') && !$this->confirm('Complete these payments and unblock the credit lines?')) {
            $this->warn('Aborted.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($blockedLines, $payments) {
            Payment::whereKey($payments->modelKeys())->update(['status' => 'completed']);
            foreach ($blockedLines as $creditLine) {
                $creditLine->unblock();
            }
        });

        Log::warning('Random credit manually unblocked', [
            'user_id' => $user->id,
            'credit_line_ids' => $blockedLines->modelKeys(),
            'payment_ids' => $payments->modelKeys(),
        ]);

        $this->info("Completed {$payments->count()} payment(s) and unblocked {$blockedLines->count()} credit line(s).");

        return self::SUCCESS;
    }
}
