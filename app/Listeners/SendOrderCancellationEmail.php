<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Mail\OrderCancellationMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderCancellationEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The name of the queue the job should be sent to.
     *
     * @var string|null
     */
    public $queue = 'email';

    /**
     * The time (seconds) before the job should be processed.
     *
     * @var int
     */
    public $delay = 5;

    /**
     * One cancellation notice for every party that was told about the order:
     * the branch that placed it, the secondary branch when a primary branch
     * ordered for it, the contact named on the order, and the system inbox
     * that receives orders. A repeated address is notified once.
     */
    public function handle(OrderCanceled $event): void
    {
        $event->order->loadMissing(['user', 'customer', 'contact']);

        $order = $event->order;
        $recipients = $this->emailAddresses($order->user);

        if ($order->user !== null && $order->customer !== null && ! $order->user->is($order->customer)) {
            $recipients = $this->merge($recipients, $this->emailAddresses($order->customer));
        }

        $contact = $this->contactEmail($order);

        if ($contact !== null) {
            $recipients = $this->merge($recipients, [$contact]);
        }

        $warehouse = $this->warehouseEmail();

        if ($warehouse === null) {
            Log::warning('SendOrderCancellationEmail: WAREHOUSE_EMAIL_RECIPIENT is not configured, the system inbox is skipped.', [
                'order_id' => $order->id,
            ]);
        } else {
            $recipients = $this->merge($recipients, [$warehouse]);
        }

        if ($recipients === []) {
            Log::warning('SendOrderCancellationEmail: order cancellation has no recipients, skipping email send.', [
                'order_id' => $order->id,
            ]);

            return;
        }

        Mail::to($recipients)->send(new OrderCancellationMail($order));
    }

    /**
     * Email frozen on the order at checkout, or the contact's current email when the
     * snapshot has none.
     */
    private function contactEmail(Order $order): ?string
    {
        $meta = $order->order_meta;
        $snapshot = is_array($meta) ? ($meta['contact']['email'] ?? null) : null;
        $raw = is_string($snapshot) && trim($snapshot) !== ''
            ? $snapshot
            : $order->contact?->email;

        return $this->notifiableEmail(is_string($raw) ? $raw : null);
    }

    private function warehouseEmail(): ?string
    {
        $raw = config('random.warehouse_email_recipient');

        return $this->notifiableEmail(is_string($raw) ? $raw : null);
    }

    /**
     * Commercial (EMAILCOMER) and billing (EMAIL) emails of the given users, normalized
     * and without duplicates. Invalid emails are discarded so that a wrong value in Random
     * does not prevent sending to the rest, as well as emails of an excluded domain
     * (e.g. SII generic billing mailboxes).
     *
     * @return list<string>
     */
    private function emailAddresses(?User ...$users): array
    {
        return collect($users)
            ->flatMap(fn (?User $user) => [$user?->email, $user?->billing_email])
            ->map(fn (?string $email) => $this->notifiableEmail($email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $recipients
     * @param  list<string>  $extra
     * @return list<string>
     */
    private function merge(array $recipients, array $extra): array
    {
        return array_values(array_unique([...$recipients, ...$extra]));
    }

    private function notifiableEmail(?string $email): ?string
    {
        $email = User::normalizeEmail($email);

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        if (User::hasExcludedEmailDomain($email)) {
            return null;
        }

        return $email;
    }
}
