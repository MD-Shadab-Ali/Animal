<?php

namespace App\Services\Gateways;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Khalti ePayment (KPG-2).
 *
 * Unlike eSewa there is no signed form: we ask Khalti to open a payment, it
 * hands back a pidx and a link, and the pidx is what everything afterwards is
 * keyed on. Their own docs say to treat the redirect as a prompt and settle it
 * with the lookup call, which is what verify() does.
 */
class KhaltiGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'khalti';
    }

    private function baseUrl(): string
    {
        return config('services.khalti.mode') === 'live'
            ? 'https://khalti.com/api/v2'
            : 'https://dev.khalti.com/api/v2';
    }

    private function request()
    {
        $key = config('services.khalti.secret_key');

        if (blank($key)) {
            throw ValidationException::withMessages([
                'method' => ['Khalti is not configured yet. Please choose another payment method.'],
            ]);
        }

        return Http::timeout(20)
            ->acceptJson()
            ->withHeaders(['Authorization' => 'Key '.$key]);
    }

    /** Khalti counts in paisa; the rest of this app counts in rupees. */
    public static function toPaisa(float $rupees): int
    {
        return (int) round($rupees * 100);
    }

    public static function toRupees(int|float|null $paisa): ?float
    {
        return $paisa === null ? null : round(((float) $paisa) / 100, 2);
    }

    public function start(Payment $payment): array
    {
        // Whatever the money is for -- an order of goats, or a booked room.
        // Reaching straight for ->order here was a fatal on the second of those.
        $subject = $payment->subject();

        $response = $this->request()->post($this->baseUrl().'/epayment/initiate/', [
            'return_url' => route('api.v1.payments.return', ['gateway' => 'khalti']),
            'website_url' => rtrim(config('app.frontend_url'), '/'),
            'amount' => self::toPaisa((float) $payment->amount),
            'purchase_order_id' => $payment->gateway_ref,
            'purchase_order_name' => ucfirst($subject->paymentSubjectNoun())
                .' '.$subject->paymentReference(),
            'customer_info' => array_filter([
                'name' => $subject->payerName(),
                'email' => $subject->payerEmail(),
                'phone' => $subject->payerPhone(),
            ]),
        ]);

        if (! $response->successful() || blank($response->json('pidx'))) {
            Log::error('Khalti initiate failed', [
                'payment' => $payment->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw ValidationException::withMessages([
                'method' => ['Khalti could not start this payment. Please try again.'],
            ]);
        }

        /*
         * The pidx replaces our reference as the key from here on: it is what
         * lookup expects, and what Khalti will quote back on the redirect.
         */
        $payment->forceFill([
            'gateway_ref' => $response->json('pidx'),
            'gateway_status' => 'Initiated',
        ])->save();

        return [
            'type' => 'redirect',
            'url' => $response->json('payment_url'),
        ];
    }

    public function verify(Payment $payment): GatewayResult
    {
        try {
            $response = $this->request()->post($this->baseUrl().'/epayment/lookup/', [
                'pidx' => $payment->gateway_ref,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Khalti lookup failed', [
                'payment' => $payment->id, 'error' => $e->getMessage(),
            ]);

            return new GatewayResult(GatewayResult::PENDING, rawStatus: 'unreachable');
        }

        $body = $response->json() ?? [];
        $status = (string) ($body['status'] ?? 'Unknown');

        return new GatewayResult(
            status: match ($status) {
                'Completed' => GatewayResult::PAID,
                'Pending', 'Initiated' => GatewayResult::PENDING,
                'Expired', 'User canceled', 'Refunded' => GatewayResult::FAILED,
                default => GatewayResult::UNKNOWN,
            },
            amount: self::toRupees($body['total_amount'] ?? null),
            transactionId: $body['transaction_id'] ?? null,
            rawStatus: $status,
            payload: $body,
        );
    }
}
