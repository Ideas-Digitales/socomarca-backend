<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmationEmail implements ShouldQueue
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
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * A purchase for the buyer's own branch keeps the single confirmation. A primary
     * branch ordering for a secondary one gets two: the buyer is told the purchase
     * was for that branch, and the branch is told a purchase was placed for it.
     * An address that belongs to both sides is sent only the buyer's copy.
     * The contact named on the order also receives the order information, unless that
     * address already got the buyer or branch confirmation.
     */
    public function handle(OrderCompleted $event): void
    {
        $event->order->loadMissing([
            'user',
            'customer',
            'contact',
            'orderDetails.product',
            'payments.paymentMethod',
            'randomDocuments',
        ]);

        $order = $event->order;
        $forAnotherBranch = $order->user !== null
            && $order->customer !== null
            && ! $order->user->is($order->customer);

        if (! $forAnotherBranch) {
            $recipients = $this->emailAddresses($order->user, $order->customer);
            $this->send($order, $recipients, OrderConfirmationMail::AUDIENCE_SELF);
            $this->sendContactCopy($order, $recipients);

            return;
        }

        $buyerRecipients = $this->emailAddresses($order->user);
        // An address shared with the buyer already receives the buyer's copy.
        $branchRecipients = array_values(array_diff(
            $this->emailAddresses($order->customer),
            $buyerRecipients,
        ));

        if ($buyerRecipients === [] && $branchRecipients === []) {
            $this->warnNoRecipients($order);
        }

        if ($buyerRecipients !== []) {
            Mail::to($buyerRecipients)->send(new OrderConfirmationMail($order, OrderConfirmationMail::AUDIENCE_BUYER));
        }

        if ($branchRecipients !== []) {
            Mail::to($branchRecipients)->send(new OrderConfirmationMail($order, OrderConfirmationMail::AUDIENCE_BRANCH));
        }

        $this->sendContactCopy($order, array_merge($buyerRecipients, $branchRecipients));
    }

    /**
     * @param  list<string>  $recipients
     */
    private function send(Order $order, array $recipients, string $audience): void
    {
        if ($recipients === []) {
            $this->warnNoRecipients($order);

            return;
        }

        Mail::to($recipients)->send(new OrderConfirmationMail($order, $audience));
    }

    /**
     * @param  list<string>  $alreadySent
     */
    private function sendContactCopy(Order $order, array $alreadySent): void
    {
        $email = $this->contactEmail($order);

        if ($email === null || in_array($email, $alreadySent, true)) {
            return;
        }

        Mail::to($email)->send(new OrderConfirmationMail($order, OrderConfirmationMail::AUDIENCE_CONTACT));
    }

    /**
     * Email frozen on the order at checkout, or the contact's current email when the
     * snapshot has none.
     */
    private function contactEmail(Order $order): ?string
    {
        $snapshot = $order->order_meta['contact']['email'] ?? null;
        $raw = is_string($snapshot) && trim($snapshot) !== ''
            ? $snapshot
            : $order->contact?->email;
        $email = User::normalizeEmail(is_string($raw) ? $raw : null);

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        if (User::hasExcludedEmailDomain($email)) {
            return null;
        }

        return $email;
    }

    private function warnNoRecipients(Order $order): void
    {
        Log::warning(
            'SendOrderConfirmationEmail: order has no customer email, skipping email send.',
            [
                'order_id' => $order->id,
            ],
        );
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
            ->map(fn (?string $email) => User::normalizeEmail($email))
            ->filter(fn (?string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->reject(fn (string $email) => User::hasExcludedEmailDomain($email))
            ->unique()
            ->values()
            ->all();
    }
}
