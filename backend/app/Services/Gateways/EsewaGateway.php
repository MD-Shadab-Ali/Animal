<?php

namespace App\Services\Gateways;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * eSewa ePay v2.
 *
 * The buyer is carried to eSewa by a signed HTML form, pays, and is redirected
 * back with a base64 payload. That payload is not evidence -- the status API
 * is -- so it only ever triggers a call to verify().
 */
class EsewaGateway implements PaymentGateway
{
    /** Exactly these fields, in this order, are what the signature covers. */
    private const SIGNED_FIELDS = 'total_amount,transaction_uuid,product_code';

    public function code(): string
    {
        return 'esewa';
    }

    private function live(): bool
    {
        return config('services.esewa.mode') === 'live';
    }

    private function formUrl(): string
    {
        return $this->live()
            ? 'https://epay.esewa.com.np/api/epay/main/v2/form'
            : 'https://rc-epay.esewa.com.np/api/epay/main/v2/form';
    }

    private function statusUrl(): string
    {
        return $this->live()
            ? 'https://esewa.com.np/api/epay/transaction/status/'
            : 'https://rc.esewa.com.np/api/epay/transaction/status/';
    }

    /**
     * base64(HMAC-SHA256(secret, "total_amount=..,transaction_uuid=..,product_code=..")).
     *
     * eSewa compares byte for byte, so the amount has to be formatted the same
     * way here as in the form field -- hence one helper for both.
     */
    public function sign(string $totalAmount, string $transactionUuid): string
    {
        $message = 'total_amount='.$totalAmount
            .',transaction_uuid='.$transactionUuid
            .',product_code='.config('services.esewa.product_code');

        return base64_encode(hash_hmac('sha256', $message, config('services.esewa.secret'), true));
    }

    /** eSewa rejects thousands separators; two decimals, plain. */
    public static function amount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    public function start(Payment $payment): array
    {
        $total = self::amount((float) $payment->amount);

        return [
            'type' => 'form',
            'url' => $this->formUrl(),
            'fields' => [
                'amount' => $total,
                'tax_amount' => '0',
                'total_amount' => $total,
                'transaction_uuid' => $payment->gateway_ref,
                'product_code' => config('services.esewa.product_code'),
                'product_service_charge' => '0',
                'product_delivery_charge' => '0',
                'success_url' => route('api.v1.payments.return', ['gateway' => 'esewa']),
                'failure_url' => route('api.v1.payments.return', ['gateway' => 'esewa']),
                'signed_field_names' => self::SIGNED_FIELDS,
                'signature' => $this->sign($total, $payment->gateway_ref),
            ],
        ];
    }

    public function verify(Payment $payment): GatewayResult
    {
        try {
            $response = Http::timeout(20)->acceptJson()->get($this->statusUrl(), [
                'product_code' => config('services.esewa.product_code'),
                'total_amount' => self::amount((float) $payment->amount),
                'transaction_uuid' => $payment->gateway_ref,
            ]);
        } catch (\Throwable $e) {
            // Unreachable is not the same as unpaid. Left pending so the
            // reconcile command asks again rather than failing the payment.
            Log::warning('eSewa status check failed', [
                'payment' => $payment->id, 'error' => $e->getMessage(),
            ]);

            return new GatewayResult(GatewayResult::PENDING, rawStatus: 'unreachable');
        }

        $body = $response->json() ?? [];
        $status = (string) ($body['status'] ?? 'UNKNOWN');

        return new GatewayResult(
            status: match ($status) {
                'COMPLETE' => GatewayResult::PAID,
                'PENDING', 'AMBIGUOUS' => GatewayResult::PENDING,
                'CANCELED', 'FULL_REFUND', 'PARTIAL_REFUND' => GatewayResult::FAILED,
                // NOT_FOUND means the session expired before anyone paid.
                default => GatewayResult::UNKNOWN,
            },
            amount: isset($body['total_amount']) ? (float) $body['total_amount'] : null,
            transactionId: $body['ref_id'] ?? null,
            rawStatus: $status,
            payload: $body,
        );
    }
}
