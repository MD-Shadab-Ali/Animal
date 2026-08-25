<?php

namespace Tests;

use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
