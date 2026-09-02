<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Contracts\Payable;
use App\Http\Resources\PaymentEntryResource;
use App\Models\Booking;
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
     * Open an attempt against an order. Answers with where to send the buyer.
     */
    public function start(Request $request, string $orderNumber, string $gateway)
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->open($request, $gateway, $order);
    }

    /**
     * The same thing for a booked room.
     *
     * Nothing below this point knows the difference, and that is the point: the
     * attempt, the redirect and the settlement are the gateway's business, not
     * the subject's. Only finding the record differs.
     */
    public function startForBooking(Request $request, string $number, string $gateway)
    {
        $booking = Booking::where('booking_number', $number)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->open($request, $gateway, $booking);
    }

    private function open(Request $request, string $gateway, Payable $subject)
    {
        $data = $request->validate([
            // Optional on purpose. What is owed today follows from the plan the
            // order or booking was placed on, and it already knows it -- a
            // browser recomputing money is a browser that can get it wrong.
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        $start = $this->gateways->begin(
            $subject,
            $request->user(),
            $gateway,
            (float) ($data['amount'] ?? $subject->amountDueNow()),
        );

        // The attempt they had open turned out to be paid, so there is nowhere
        // to send them -- it has already moved on.
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
        // Try each thing the provider might have called this attempt, because
        // getting it wrong is silent: settle() simply finds no payment and the
        // buyer is told nothing happened.
        $payment = null;

        foreach ($this->referencesFrom($request, $gateway) as $reference) {
            $payment = $this->gateways->settle($gateway, $reference);

            if ($payment) {
                break;
            }
        }

        $status = match ($payment?->status) {
            'confirmed' => 'success',
            'rejected' => 'failed',
            'pending' => 'pending',
            default => 'unknown',
        };

        $target = rtrim(config('app.frontend_url'), '/');

        // Back to the order or the booking it paid for, or to the account if we
        // could not work out which -- never a dead end.
        $path = $payment?->subject()?->paymentSubjectPath() ?? '/account';

        return redirect()->away($target.$path.'?payment='.$status);
    }

    /**
     * Everything the provider might be calling this attempt, best first.
     *
     * Khalti is asked to open a payment under our own reference and answers
     * with a pidx, which then *replaces* that reference on the row -- the pidx
     * is what its lookup call expects. But its redirect quotes back the
     * original purchase_order_id as well, and matching on that found nothing,
     * so a paid order sat there asking to be confirmed by hand. Both are tried
     * now, and whichever finds the payment wins.
     */
    private function referencesFrom(Request $request, string $gateway): array
    {
        if ($gateway === 'khalti') {
            return array_values(array_filter([
                $request->query('pidx'),
                $request->query('purchase_order_id'),
            ]));
        }

        if ($gateway === 'esewa') {
            // eSewa returns one base64 blob rather than separate parameters.
            $decoded = json_decode(base64_decode((string) $request->query('data'), true) ?: '[]', true);

            return array_values(array_filter([
                $decoded['transaction_uuid'] ?? null,
                $request->query('transaction_uuid'),
            ]));
        }

        return [];
    }
}
