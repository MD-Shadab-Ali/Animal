<?php

namespace Tests;

use App\Models\Order;
use App\Services\PaymentService;
use App\Services\RecaptchaVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The robot check stands down for the suite.
     *
     * Sixteen places sign in or sign up as a step towards testing something
     * else, and none of them is about reCAPTCHA. Making each hold a live token
     * would mean calling Google from the test run, which is slow, flaky and
     * dishonest about what those tests prove.
     *
     * The check itself is covered directly in AuthRecaptchaAndGoogleTest, which
     * puts the real verifier back and fakes Google's reply instead.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->swap(RecaptchaVerifier::class, new class extends RecaptchaVerifier
        {
            public function assertValid(?string $token, ?string $ip = null): void {}
        });
    }

    /**
     * Settle an order in full through the payment ledger.
     *
     * Orders cannot be delivered unpaid, so any fixture that needs a delivered
     * order has to put the money in first — same as real life.
     */
    protected function payInFull(Order $order): Order
    {
        $order = $order->fresh();

        if ($order->balance_due > 0) {
            app(PaymentService::class)->record($order, [
                'amount' => $order->balance_due,
                'method' => $order->payment_method,
            ]);
        }

        return $order->fresh();
    }

    /** Pay for an order and hand it over, the way a real one closes. */
    protected function markDelivered(Order $order): Order
    {
        $order = $this->payInFull($order);

        if ($order->status !== 'delivered') {
            $order->update(['status' => 'delivered']);
        }

        return $order->fresh();
    }
}
