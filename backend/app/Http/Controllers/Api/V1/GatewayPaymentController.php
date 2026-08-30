<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentEntryResource;
use App\Models\Order;
use App\Services\GatewayPaymentService;
use Illuminate\Http\Request;

/**
 * Starting an online payment, and catching the buyer when they come back.
 */
class GatewayPaymentController extends Controller
{
    public function __construct(private GatewayPaymentService $gateways) {}

    /**
     * Open an attempt. Answers with where to send the buyer.
     */
    public function start(Request $request, string $orderNumber, string $gateway)
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            // Optional on purpose. What is owed today follows from the plan the
            // order was placed on, and the order already knows it -- a browser
            // recomputing money is a browser that can get it wrong.
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        $start = $this->gateways->begin(
            $order,
            $request->user(),
            $gateway,
            (float) ($data['amount'] ?? $order->amount_due_now),
        );

        // The attempt they had open turned out to be paid, so there is nowhere
        // to send them -- the order already moved on.
        if (($start['type'] ?? null) === 'settled') {
            return response()->json([
                'message' => 'That payment already went through.',
                'data' => ['type' => 'settled', 'payment' => new PaymentEntryResource($start['payment'])],
            ]);
        }

        return response()->json([
            'data' => [
                'type' => $start['type'],
                'url' => $start['url'],
                'fields' => $start['fields'] ?? null,
            ],
        ]);
    }

    /**
     * Where the provider sends the buyer's browser afterwards.
     *
     * Deliberately unauthenticated: the buyer arrives from esewa.com.np or
     * khalti.com with no token on the request. Nothing here trusts the query
     * string -- it names an attempt, and the attempt is then verified with the
     * provider before anything is confirmed.
     */
    public function returned(Request $request, string $gateway)
    {
        $reference = $this->referenceFrom($request, $gateway);
        $payment = $reference ? $this->gateways->settle($gateway, $reference) : null;

        $status = match ($payment?->status) {
            'confirmed' => 'success',
            'rejected' => 'failed',
            'pending' => 'pending',
            default => 'unknown',
        };

        $target = rtrim(config('app.frontend_url'), '/');

        // Back to the order it paid for, or to the order list if we could not
        // work out which one -- never a dead end.
        $path = $payment?->order
            ? '/account/orders/'.$payment->order->order_number
            : '/account';

        return redirect()->away($target.$path.'?payment='.$status);
    }

    /**
     * Each provider names the attempt differently on the way back.
     */
    private function referenceFrom(Request $request, string $gateway): ?string
    {
        if ($gateway === 'khalti') {
            return $request->query('purchase_order_id') ?: $request->query('pidx');
        }

        if ($gateway === 'esewa') {
            // eSewa returns one base64 blob rather than separate parameters.
            $decoded = json_decode(base64_decode((string) $request->query('data'), true) ?: '[]', true);

            return $decoded['transaction_uuid'] ?? null;
        }

        return null;
    }
}
