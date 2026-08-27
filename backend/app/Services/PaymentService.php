<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
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
 * The order's `paid_amount` and `payment_status` are never written by hand
 * anywhere else — they are derived from the confirmed rows in the ledger, so
 * the two can never drift apart.
 */
class PaymentService
{
    /**
     * A customer telling us they have sent money.
     *
     * It is a claim, not a receipt: nothing moves on the order until staff
     * confirm it against the account.
     */
    public function claim(Order $order, User $payer, array $data): Payment
    {
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'amount' => ['This order has been cancelled.'],
            ]);
        }

        if ($order->isFullyPaid()) {
            throw ValidationException::withMessages([
                'amount' => ['This order is already paid in full.'],
            ]);
        }

        // One open claim at a time. The storefront hides the form while one is
        // outstanding, but a hidden form is not a guard — without this, a
        // double submit or a stale tab files the same payment twice and staff
        // have to work out which of two identical rows is real.
        if ($order->payments()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'amount' => ['You have already told us about a payment on this order. '
                    .'We will confirm it shortly.'],
            ]);
        }

        $method = PaymentMethod::where('code', $data['method'])->first();

        if (! $method || ! $method->is_active) {
            throw ValidationException::withMessages([
                'method' => ['That payment method is not available.'],
            ]);
        }

        $payment = $this->create($order, [
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
    public function record(Order $order, array $data, ?User $by = null): Payment
    {
        $payment = $this->create($order, [
            'user_id'               => $order->user_id,
            'method'                => $data['method'] ?? $order->payment_method,
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

        $this->sync($order->fresh());

        return $payment;
    }

    /**
     * A buyer asking for money the order no longer needs.
     *
     * Either the order was cancelled, or it came in lighter than ordered and
     * re-priced below what was already paid.
     *
     * Filed as a refund row sitting at `pending`: nothing leaves the ledger
     * until staff have actually sent the money and said so, exactly as a
     * payment claim proves nothing until it is checked.
     */
    public function requestRefund(Order $order, User $payer, array $data): Payment
    {
        // Two ways an order can owe money back: it was cancelled, or it was
        // re-priced downwards at the door after the buyer had already paid.
        if (! $order->isRefundable()) {
            throw ValidationException::withMessages([
                'refund' => ['There is nothing to refund on this order.'],
            ]);
        }

        if ($order->payments()->refunds()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'refund' => ['You have already asked for a refund on this order. '
                    .'We are working on it.'],
            ]);
        }

        $payment = $this->create($order, [
            'user_id'           => $payer->id,
            'method'            => $data['method'] ?? $order->payment_method,
            'amount'            => $order->refundable_amount,
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

        $order = $payment->order->fresh();

        $this->sync($order, $payment);

        $payment = $payment->fresh();

        $order->user?->notify($payment->isRefund()
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

        $this->sync($payment->order->fresh(), $payment);

        return $payment->fresh();
    }

    /**
     * Re-derive the order's money columns from the ledger, then close the order
     * if the last thing it was waiting for was the money.
     */
    /**
     * @param  Payment|null  $trigger  the payment that prompted this, when there was one
     */
    public function sync(Order $order, ?Payment $trigger = null): Order
    {
        $paid = round((float) Payment::query()
            ->where('order_id', $order->id)
            ->confirmed()
            ->get()
            ->sum(fn (Payment $payment) => $payment->signed_amount), 2);

        $total = (float) $order->total;

        // Everything came back out again — the order was refunded, not unpaid.
        $refunded = $paid <= 0 && Payment::where('order_id', $order->id)
            ->confirmed()
            ->where('type', 'refund')
            ->exists();

        $order->forceFill([
            'paid_amount'    => max($paid, 0),
            'payment_status' => match (true) {
                $refunded              => 'refunded',
                $paid <= 0             => 'unpaid',
                $paid + 0.01 >= $total => 'paid',
                default                => 'partially_paid',
            },
        ])->save();

        return $this->advanceOnPayment($order->fresh(), $trigger);
    }

    /**
     * Move the order on to whatever the money has just unlocked.
     *
     * Confirming a payment is staff saying "this really arrived", which is the
     * same act as confirming the order — so they should not then have to go and
     * say it a second time in a different dropdown.
     */
    private function advanceOnPayment(Order $order, ?Payment $trigger = null): Order
    {
        // A refund is money leaving. Confirming one used to make the order look
        // settled -- paid had just come down to meet the total -- and closed it
        // as delivered on the strength of a payment going the other way.
        if ($trigger?->isRefund()) {
            return $order;
        }

        // What the buyer promised up front is in, so the order is no longer
        // merely placed: it is committed, and the goat can be prepared. Only
        // from 'pending' — anything further along has already been moved by
        // staff or the seller, and this must never drag an order backwards.
        if ($order->status === 'pending'
            && (float) $order->paid_amount > 0
            && ! $order->awaiting_advance
        ) {
            $order->update(['status' => 'confirmed']);

            $order = $order->fresh();
        }

        return $this->closeIfSettled($order);
    }

    /**
     * Money handed over at the door is evidence the goat got there.
     *
     * Only when the money was actually due at the door. An order paid in full
     * up front settled long before the animal moved, so its balance says
     * nothing about whether anything arrived -- a person has to say that, and
     * the buyer has a button for it. Closing those on payment released the
     * seller's earnings for a goat nobody had seen.
     *
     * Only from `out_for_delivery`, for the same reason: paying for a goat
     * still standing on the farm does not deliver it.
     */
    private function closeIfSettled(Order $order): Order
    {
        if (! Setting::get('auto_deliver_on_payment', true)) {
            return $order;
        }

        // Cash on delivery is collected at the handover, and the last slice of
        // an advance is too. Anything else was paid before the journey began.
        if (! in_array($order->payment_plan, ['on_delivery', 'advance'], true)) {
            return $order;
        }

        if ($order->status !== 'out_for_delivery' || ! $order->isFullyPaid()) {
            return $order;
        }

        $order->update(['status' => 'delivered']);

        return $order->fresh();
    }

    private function create(Order $order, array $attributes): Payment
    {
        return DB::transaction(fn () => Payment::create($attributes + [
            'reference' => $this->reference(),
            'order_id'  => $order->id,
            'currency'  => $order->currency,
        ]));
    }

    private function reference(): string
    {
        do {
            $reference = 'PAY-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (Payment::where('reference', $reference)->exists());

        return $reference;
    }
}
