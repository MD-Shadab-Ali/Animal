<?php

namespace App\Services;

use App\Contracts\Payable;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\PaymentSubmittedNotification;
use App\Notifications\RefundRequestedNotification;
use App\Notifications\RefundSentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything that happens to money coming in.
 *
 * The subject's `paid_amount` and `payment_status` are never written by hand
 * anywhere else — they are derived from the confirmed rows in the ledger, so
 * the two can never drift apart.
 *
 * It used to take an Order. It now takes a Payable, which is either an order of
 * goats or a room booked for some nights, and the change was almost entirely a
 * change of nouns: a claim is still a claim, an advance is still an advance,
 * and a refund is still a row pointing the other way. What genuinely differs
 * between the two -- what a payment unlocks, what "already settled" means -- is
 * asked of the subject rather than decided here. See App\Contracts\Payable.
 */
class PaymentService
{
    /**
     * A customer telling us they have sent money.
     *
     * It is a claim, not a receipt: nothing moves on the order or the booking
     * until staff confirm it against the account.
     */
    public function claim(Payable $subject, User $payer, array $data): Payment
    {
        if ($subject->isCancelled()) {
            throw ValidationException::withMessages([
                'amount' => [$subject->cancelledMessage()],
            ]);
        }

        if ($subject->isFullyPaid()) {
            throw ValidationException::withMessages([
                'amount' => [$subject->settledMessage()],
            ]);
        }

        // One open claim at a time. The storefront hides the form while one is
        // outstanding, but a hidden form is not a guard — without this, a
        // double submit or a stale tab files the same payment twice and staff
        // have to work out which of two identical rows is real.
        if ($subject->payments()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'amount' => ['You have already told us about a payment on this '
                    .$subject->paymentSubjectNoun().'. We will confirm it shortly.'],
            ]);
        }

        $method = PaymentMethod::where('code', $data['method'])->first();

        if (! $method || ! $method->is_active) {
            throw ValidationException::withMessages([
                'method' => ['That payment method is not available.'],
            ]);
        }

        /*
         * A gateway settles itself. Letting a buyer also claim one would put
         * back the very thing the integration removes -- a human deciding
         * whether an assertion is true -- and would double-count the money
         * once the provider confirms the real attempt. Staff can still record
         * one by hand through record(), for an outage or a stuck transaction.
         */
        if ($method->isGateway()) {
            throw ValidationException::withMessages([
                'method' => [$method->name.' payments are confirmed automatically. '
                    .'Use the pay button on your '.$subject->paymentSubjectNoun()
                    .', and contact us if it does not go through.'],
            ]);
        }

        $payment = $this->create($subject, [
            'user_id'               => $payer->id,
            'method'                => $method->code,
            'amount'                => $data['amount'],
            'type'                  => 'payment',
            'status'                => 'pending',
            'source'                => 'customer',
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'proof'                 => $data['proof'] ?? null,
            'note'                  => $data['note'] ?? null,
            'paid_at'               => $data['paid_at'] ?? now(),
            'created_by'            => $payer->id,
        ]);

        $staff = User::staffRecipients();

        if ($staff->isNotEmpty()) {
            Notification::send($staff, new PaymentSubmittedNotification($payment));
        }

        return $payment;
    }

    /**
     * Staff entering money they have in hand — cash from a rider, a transfer
     * already visible on the statement. Confirmed on the spot, because the
     * person recording it is the person who checked it.
     */
    public function record(Payable $subject, array $data, ?User $by = null): Payment
    {
        $payment = $this->create($subject, [
            'user_id'               => $subject->payer()?->getKey(),
            'method'                => $data['method'] ?? $subject->defaultPaymentMethod(),
            'amount'                => $data['amount'],
            'type'                  => $data['type'] ?? 'payment',
            'status'                => 'confirmed',
            'source'                => 'staff',
            'transaction_reference' => $data['transaction_reference'] ?? null,
            'note'                  => $data['note'] ?? null,
            'paid_at'               => $data['paid_at'] ?? now(),
            'confirmed_at'          => now(),
            'confirmed_by'          => $by?->id,
            'created_by'            => $by?->id,
        ]);

        $this->sync($subject->fresh());

        return $payment;
    }

    /**
     * A buyer asking for money the order or the booking no longer needs.
     *
     * Either it was cancelled, or it was re-priced below what had already been
     * paid -- a goat that came in lighter than ordered, a stay cut short.
     *
     * Filed as a refund row sitting at `pending`: nothing leaves the ledger
     * until staff have actually sent the money and said so, exactly as a
     * payment claim proves nothing until it is checked.
     */
    public function requestRefund(Payable $subject, User $payer, array $data): Payment
    {
        if (! $subject->isRefundable()) {
            throw ValidationException::withMessages([
                'refund' => ['There is nothing to refund on this '
                    .$subject->paymentSubjectNoun().'.'],
            ]);
        }

        if ($subject->payments()->refunds()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'refund' => ['You have already asked for a refund on this '
                    .$subject->paymentSubjectNoun().'. We are working on it.'],
            ]);
        }

        $payment = $this->create($subject, [
            'user_id'           => $payer->id,
            'method'            => $data['method'] ?? $subject->defaultPaymentMethod(),
            'amount'            => $subject->refundableAmount(),
            'type'              => 'refund',
            'status'            => 'pending',
            'source'            => 'customer',
            'refund_to_name'    => $data['refund_to_name'] ?? null,
            'refund_to_account' => $data['refund_to_account'] ?? null,
            'refund_to_bank'    => $data['refund_to_bank'] ?? null,
            'refund_reason'     => $data['refund_reason'] ?? null,
            'created_by'        => $payer->id,
        ]);

        $staff = User::staffRecipients();

        if ($staff->isNotEmpty()) {
            Notification::send($staff, new RefundRequestedNotification($payment));
        }

        return $payment;
    }

    /** Staff have seen the money land. */
    public function confirm(Payment $payment, ?User $by = null): Payment
    {
        if ($payment->status === 'confirmed') {
            return $payment;
        }

        $payment->update([
            'status'       => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by' => $by?->id,
        ]);

        $subject = $payment->subject()?->fresh();

        if ($subject) {
            $this->sync($subject, $payment);
        }

        $payment = $payment->fresh();

        $subject?->payer()?->notify($payment->isRefund()
            ? new RefundSentNotification($payment)
            : new PaymentReceivedNotification($payment));

        return $payment;
    }

    /** It never arrived, or it was not what the customer said it was. */
    public function reject(Payment $payment, ?string $reason = null, ?User $by = null): Payment
    {
        $payment->update([
            'status'       => 'rejected',
            'note'         => $reason ?: $payment->note,
            'confirmed_at' => null,
            'confirmed_by' => $by?->id,
        ]);

        if ($subject = $payment->subject()?->fresh()) {
            $this->sync($subject, $payment);
        }

        return $payment->fresh();
    }

    /**
     * Re-derive the money columns from the ledger, then let the subject move on
     * to whatever that has just unlocked.
     *
     * @param  Payment|null  $trigger  the payment that prompted this, when there was one
     */
    public function sync(Payable $subject, ?Payment $trigger = null): Payable
    {
        $paid = round((float) Payment::query()
            ->where($subject->paymentForeignKey(), $subject->getKey())
            ->confirmed()
            ->get()
            ->sum(fn (Payment $payment) => $payment->signed_amount), 2);

        $total = $subject->paymentTotal();

        // Everything came back out again — it was refunded, not unpaid.
        $refunded = $paid <= 0 && Payment::query()
            ->where($subject->paymentForeignKey(), $subject->getKey())
            ->confirmed()
            ->where('type', 'refund')
            ->exists();

        $subject->forceFill([
            'paid_amount'    => max($paid, 0),
            'payment_status' => match (true) {
                $refunded              => 'refunded',
                $paid <= 0             => 'unpaid',
                $paid + 0.01 >= $total => 'paid',
                default                => 'partially_paid',
            },
        ])->save();

        return $subject->fresh()->settleAfterPayment($trigger);
    }

    private function create(Payable $subject, array $attributes): Payment
    {
        return DB::transaction(fn () => Payment::create($attributes + [
            'reference' => $this->reference(),
            $subject->paymentForeignKey() => $subject->getKey(),
            'currency'  => $subject->paymentCurrency(),
        ]));
    }

    /** Public so the gateway service can stamp its rows the same way. */
    public function reference(): string
    {
        do {
            $reference = 'PAY-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (Payment::where('reference', $reference)->exists());

        return $reference;
    }
}
