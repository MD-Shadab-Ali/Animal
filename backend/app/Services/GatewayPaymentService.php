<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Gateways\EsewaGateway;
use App\Services\Gateways\KhaltiGateway;
use App\Services\Gateways\PaymentGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payments that confirm themselves.
 *
 * The manual flow asks a buyer to say they paid and a member of staff to agree.
 * These methods replace both halves with a question put to the provider, whose
 * answer is the only thing that may confirm anything here.
 */
class GatewayPaymentService
{
    public function __construct(
        private PaymentService $payments,
        private EsewaGateway $esewa,
        private KhaltiGateway $khalti,
    ) {}

    /** Null for cash on delivery and bank transfer: nobody to ask. */
    public function gatewayFor(string $code): ?PaymentGateway
    {
        // Keep in step with PaymentMethod::GATEWAY_CODES, which is what the
        // manual claim path checks against.
        return match ($code) {
            'esewa' => $this->esewa,
            'khalti' => $this->khalti,
            default => null,
        };
    }

    public function isGateway(string $code): bool
    {
        return $this->gatewayFor($code) !== null;
    }

    /**
     * Open an attempt and say where to send the buyer.
     */
    public function begin(Order $order, User $payer, string $methodCode, float $amount): array
    {
        $gateway = $this->gatewayFor($methodCode);

        if (! $gateway) {
            throw ValidationException::withMessages([
                'method' => ['That method is settled by hand, not online.'],
            ]);
        }

        $method = PaymentMethod::where('code', $methodCode)->first();

        if (! $method || ! $method->is_active) {
            throw ValidationException::withMessages([
                'method' => ['That payment method is not available.'],
            ]);
        }

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

        // balance_due, matching the manual claim path. An accessor that does not
        // exist reads as null, casts to zero, and turns every amount into an
        // overpayment -- which is exactly what it did.
        if ($amount <= 0 || $amount - (float) $order->balance_due > 0.01) {
            throw ValidationException::withMessages([
                'amount' => ['That is more than is left to pay on this order.'],
            ]);
        }

        // A buyer who closed the tab has a pending attempt sitting there. Ask
        // about it before opening another, or an abandoned attempt would block
        // every retry -- and a paid-but-unreported one would be lost.
        $settled = $this->closeOpenAttempts($order);

        if ($settled) {
            return ['type' => 'settled', 'payment' => $settled];
        }

        $payment = Payment::create([
            'reference' => $this->payments->reference(),
            'order_id' => $order->id,
            'user_id' => $payer->id,
            'currency' => $order->currency,
            'method' => $method->code,
            'amount' => $amount,
            'type' => 'payment',
            'status' => 'pending',
            'source' => 'gateway',
            'created_by' => $payer->id,
            'gateway' => $method->code,
            // Ours, not theirs, and made before the buyer leaves: an attempt
            // that never comes back is still something we can ask about.
            'gateway_ref' => strtoupper($order->order_number.'-'.Str::random(6)),
        ]);

        try {
            return $gateway->start($payment->fresh()) + ['payment' => $payment];
        } catch (\Throwable $e) {
            /*
             * Nothing was opened at the provider, so this row stands for
             * nothing. Left behind it tells the buyer "checking your payment"
             * about a payment that never started, and blocks their next
             * attempt as an outstanding one.
             */
            $payment->delete();

            throw $e;
        }
    }

    /**
     * The only path that turns gateway money into a confirmed payment.
     *
     * Safe to call as often as it happens to be called: the redirect, a
     * refresh and the reconcile command all land here.
     */
    public function settle(string $gatewayCode, string $reference): ?Payment
    {
        $gateway = $this->gatewayFor($gatewayCode);

        $payment = Payment::where('gateway', $gatewayCode)
            ->where('gateway_ref', $reference)
            ->first();

        if (! $gateway || ! $payment) {
            return null;
        }

        // Already settled by whichever report arrived first.
        if (in_array($payment->status, ['confirmed', 'rejected'], true)) {
            return $payment;
        }

        $result = $gateway->verify($payment);

        $payment->forceFill([
            'gateway_status' => $result->rawStatus,
            'gateway_payload' => $result->payload,
            'gateway_txn_id' => $result->transactionId ?: $payment->gateway_txn_id,
        ])->save();

        if ($result->isPending()) {
            return $payment->fresh();
        }

        if (! $result->isPaid()) {
            return $this->payments->reject(
                $payment->fresh(),
                'The gateway reported: '.($result->rawStatus ?: 'no payment'),
            );
        }

        /*
         * Paid, but for how much? A status on its own would let someone pay a
         * hundred rupees against a twenty-five thousand rupee order and have it
         * marked settled, so the amount is checked before anything is
         * confirmed.
         */
        if ($result->amount !== null && abs($result->amount - (float) $payment->amount) > 0.01) {
            Log::critical('Gateway amount mismatch', [
                'payment' => $payment->id,
                'expected' => (float) $payment->amount,
                'reported' => $result->amount,
            ]);

            return $this->payments->reject(
                $payment->fresh(),
                'The gateway reported '.$result->amount.', not '.$payment->amount.'. Needs checking by hand.',
            );
        }

        $payment->forceFill([
            'transaction_reference' => $result->transactionId,
            'paid_at' => $payment->paid_at ?: now(),
        ])->save();

        // The same call staff make, so an order advances by one path only.
        return $this->payments->confirm($payment->fresh());
    }

    /**
     * Resolve attempts left open on an order. Returns one that turned out to
     * be paid, if any, so the caller stops rather than charging twice.
     */
    public function closeOpenAttempts(Order $order): ?Payment
    {
        $open = $order->payments()
            ->where('type', 'payment')
            ->where('status', 'pending')
            ->whereNotNull('gateway')
            ->get();

        foreach ($open as $attempt) {
            $settled = $this->settle($attempt->gateway, $attempt->gateway_ref);

            if ($settled?->status === 'confirmed') {
                return $settled;
            }

            // Still pending with the provider after the buyer walked away:
            // nothing arrived, so it should not block a fresh attempt.
            if ($settled && $settled->status === 'pending') {
                $this->payments->reject($settled, 'Abandoned - a new attempt was started.');
            }
        }

        return null;
    }
}
