<?php

namespace App\Services\Gateways;

use App\Models\Payment;

/**
 * A provider that can be asked, later and independently, whether money moved.
 *
 * That second part is what separates a gateway from the manual methods: cash
 * on delivery and a bank transfer can only ever be vouched for by a person,
 * so they do not implement this.
 */
interface PaymentGateway
{
    /** Matches payment_methods.code. */
    public function code(): string;

    /**
     * Where to send the buyer to pay.
     *
     * Returns either ['type' => 'redirect', 'url' => ...] or
     * ['type' => 'form', 'url' => ..., 'fields' => [...]] -- eSewa needs a
     * POSTed form, Khalti hands back a link.
     */
    public function start(Payment $payment): array;

    /**
     * Ask the provider what really happened.
     *
     * This is the only thing that may confirm a payment. The browser comes
     * back from the provider carrying a "success" payload that a buyer could
     * write themselves, so it is treated as a hint that it is worth asking --
     * never as the answer.
     */
    public function verify(Payment $payment): GatewayResult;
}
