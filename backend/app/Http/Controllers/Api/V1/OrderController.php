<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentEntryResource;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::with('items')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $orderNumber): OrderResource
    {
        $order = Order::with(['items.goat', 'deliveryZone', 'statusHistories', 'payments'])
            ->where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return new OrderResource($order);
    }

    /**
     * The customer telling us they have sent the money.
     *
     * Deliberately not a receipt: it lands as a claim for staff to check
     * against the account, and only their confirmation moves the order.
     */
    public function pay(Request $request, string $orderNumber, PaymentService $payments): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        $data = $request->validate([
            'method'                => ['required', 'string', 'exists:payment_methods,code'],
            'amount'                => ['required', 'numeric', 'min:1', 'max:'.max($order->balance_due, 1)],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'note'                  => ['nullable', 'string', 'max:500'],
            'proof'                 => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'amount.max'      => 'That is more than the outstanding balance on this order.',
            'method.exists'   => 'That payment method is not available.',
            'proof.max'       => 'The receipt must be 5MB or smaller.',
        ]);

        if ($request->hasFile('proof')) {
            $data['proof'] = $request->file('proof')->store('payments/proof', 'public');
        }

        $payment = $payments->claim($order, $request->user(), $data);

        return response()->json([
            'message' => 'Thank you. We will check it against our account and confirm shortly.',
            'data'    => new PaymentEntryResource($payment),
        ], 201);
    }

    /**
     * The buyer asking for their money back after cancelling.
     *
     * Like a payment claim, this changes nothing on its own — it queues the
     * refund for staff to send and mark off.
     */
    public function refund(Request $request, string $orderNumber, PaymentService $payments): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        // Only a rail we can actually send money out on, and a bank transfer
        // is not a destination without the bank — the same rules the seller
        // payout details obey, for the same reason.
        $rails = PaymentMethod::payout()->get();
        $chosen = $rails->firstWhere('code', $request->input('method'));

        $data = $request->validate([
            'method'            => ['required', 'string', Rule::in($rails->pluck('code'))],
            'refund_to_name'    => ['required', 'string', 'max:255'],
            'refund_to_account' => ['required', 'string', 'max:60'],
            'refund_to_bank'    => [
                Rule::requiredIf(fn () => (bool) $chosen?->requires_bank_name),
                'nullable', 'string', 'max:255',
            ],
            'refund_reason'     => ['nullable', 'string', 'max:500'],
        ], [
            'method.in'                  => 'We cannot send money back that way.',
            'refund_to_name.required'    => 'Tell us the name on the account.',
            'refund_to_account.required' => 'Tell us the account or wallet number to send it to.',
            'refund_to_bank.required'    => 'Tell us which bank to send it to.',
        ]);

        // A wallet has no bank, so nothing misleading is stored against one.
        $data['refund_to_bank'] = $chosen?->requires_bank_name ? $data['refund_to_bank'] : null;

        $refund = $payments->requestRefund($order, $request->user(), $data);

        return response()->json([
            'message' => 'Refund requested. We will send it back and let you know.',
            'data'    => new PaymentEntryResource($refund),
        ], 201);
    }

    public function cancel(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        if (! $order->isCancellable()) {
            return response()->json([
                'message' => 'This order can no longer be cancelled. Please call us instead.',
            ], 422);
        }

        $order->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Order cancelled.',
            'data'    => new OrderResource($order->fresh()->load('items')),
        ]);
    }
}
