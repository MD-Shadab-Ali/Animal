<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Payout;
use App\Models\Setting;
use App\Services\PayoutService;
use App\Services\SellerFulfilmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerSalesController extends Controller
{
    /**
     * Orders containing this seller's goats. Only their own lines are exposed —
     * a seller never sees what someone else sold in the same order.
     */
    public function orders(Request $request): JsonResponse
    {
        $sellerId = $request->user()->seller->id;

        $orders = Order::query()
            ->whereHas('items', fn ($query) => $query->where('seller_id', $sellerId))
            ->with(['items' => fn ($query) => $query->where('seller_id', $sellerId)])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $orders->getCollection()->transform(fn (Order $order) => [
            'order_number' => $order->order_number,
            'status'       => $order->status,
            'status_label' => Order::STATUSES[$order->status] ?? $order->status,

            // Orders sourced entirely from this seller are theirs to run.
            'you_manage'   => $order->isManagedBy($request->user()->seller),
            'next_status'  => $order->isManagedBy($request->user()->seller)
                ? collect(Order::FLOW)
                    ->filter(fn (string $candidate) => $order->canAdvanceTo($candidate))
                    // Do not offer a button that is going to be refused: the
                    // order closes itself once the buyer has paid.
                    ->reject(fn (string $candidate) => $candidate === 'delivered' && ! $order->canBeDelivered())
                    ->map(fn (string $candidate) => [
                        'value' => $candidate,
                        'label' => Order::STATUSES[$candidate],
                    ])
                    ->values()
                : [],
            'awaiting_payment' => ! $order->isFullyPaid(),
            'placed_at'    => $order->created_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),

            // Buyer contact stays hidden until the platform confirms the order.
            'buyer' => in_array($order->status, ['pending', 'cancelled'], true)
                ? ['name' => $order->customer_name, 'city' => $order->city]
                : [
                    'name'  => $order->customer_name,
                    'phone' => $order->customer_phone,
                    'city'  => $order->city,
                    'area'  => $order->area,
                ],

            'items' => $order->items->map(fn (OrderItem $item) => [
                'id'         => $item->id,
                'name'       => $item->goat_name,
                'sku'        => $item->goat_sku,
                'thumbnail'  => $item->thumbnail_url,
                'quantity'   => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'commission' => (float) $item->commission_amount,
                'earning'    => (float) $item->seller_earning,
                'paid_out'   => $item->payout_id !== null,

                'fulfilment' => [
                    'status'     => $item->fulfilment_status,
                    'label'      => $item->fulfilment_label,
                    'note'       => $item->fulfilment_note,
                    'updated_at' => $item->fulfilment_updated_at?->toIso8601String(),
                    // What this seller may move it to next.
                    'next'       => $item->fulfilment_status === 'cancelled' || $order->status === 'cancelled'
                        ? []
                        : collect(OrderItem::SELLER_FLOW)
                            ->filter(fn (string $candidate) => $item->canAdvanceTo($candidate))
                            ->map(fn (string $candidate) => [
                                'value' => $candidate,
                                'label' => OrderItem::FULFILMENT_STATUSES[$candidate],
                            ])
                            ->values(),
                ],
            ]),

            'totals' => [
                'gross'      => (float) $order->items->sum('line_total'),
                'commission' => (float) $order->items->sum('commission_amount'),
                // The delivery charge the buyer paid, and whether it comes to you.
                'delivery_charge' => (float) $order->delivery_charge,
                'delivery_is_yours' => (int) $order->delivery_seller_id === $sellerId,
                'delivery_earning' => (int) $order->delivery_seller_id === $sellerId
                    ? (float) $order->delivery_earning
                    : 0.0,
                'earning'    => (float) $order->items->sum('seller_earning')
                    + ((int) $order->delivery_seller_id === $sellerId ? (float) $order->delivery_earning : 0.0),
                'buyer_paid' => (float) $order->total,
                'currency'   => $order->currency,
            ],
        ]);

        return response()->json($orders->toArray());
    }

    /**
     * Advance one of this seller's own order lines.
     *
     * Scoped to a single line on purpose: an order can contain goats from more
     * than one seller, so nobody may move the order itself.
     */
    public function updateItemStatus(Request $request, OrderItem $item, SellerFulfilmentService $fulfilment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $fulfilment->advance(
            $request->user()->seller,
            $item,
            $data['status'],
            $data['note'] ?? null
        );

        return response()->json([
            'message' => 'Marked as '.mb_strtolower($updated->fulfilment_label).'.',
            'data'    => [
                'id'         => $updated->id,
                'fulfilment' => [
                    'status'     => $updated->fulfilment_status,
                    'label'      => $updated->fulfilment_label,
                    'note'       => $updated->fulfilment_note,
                    'updated_at' => $updated->fulfilment_updated_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Move a whole order forward. Only available on orders this seller supplied
     * in full — anything with house stock or a second seller stays with staff.
     */
    public function updateOrderStatus(
        Request $request,
        string $orderNumber,
        SellerFulfilmentService $fulfilment
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        $order = Order::where('order_number', $orderNumber)->firstOrFail();

        $updated = $fulfilment->advanceOrder(
            $request->user()->seller,
            $order,
            $data['status'],
            $data['note'] ?? null
        );

        return response()->json([
            'message' => 'Order marked as '.mb_strtolower($updated->status_label).'.',
            'data'    => [
                'order_number' => $updated->order_number,
                'status'       => $updated->status,
                'status_label' => $updated->status_label,
            ],
        ]);
    }

    public function payouts(Request $request): AnonymousResourceCollection
    {
        $payouts = $request->user()->seller
            ->payouts()
            ->withCount('items')
            ->latest()
            ->paginate(10);

        return PayoutResource::collection($payouts);
    }

    /**
     * The seller asking to be paid what they have earned.
     *
     * The service does the guarding and the arithmetic; a failed guard comes
     * back as a 422 with a `payout` message the earnings page can show.
     */
    public function requestPayout(Request $request, PayoutService $payouts): JsonResponse
    {
        $payout = $payouts->request($request->user()->seller);

        return response()->json([
            'message' => 'Payout requested. We will send '.$payout->reference.' to your account shortly.',
            'data'    => new PayoutResource($payout),
        ], 201);
    }

    /** A line-by-line statement of what has been earned and what is still owed. */
    public function earnings(Request $request): JsonResponse
    {
        $seller = $request->user()->seller;

        $items = OrderItem::with('order:id,order_number,status,delivered_at,created_at,delivery_seller_id,delivery_earning')
            ->where('seller_id', $seller->id)
            ->whereHas('order', fn ($query) => $query->whereNot('status', 'cancelled'))
            ->latest()
            ->limit(100)
            ->get();

        // Delivery is earned once per order, not per goat. A seller can have two
        // goats on one order, so credit it to the first line only — otherwise the
        // column would double-count it.
        $deliveryClaimed = [];

        $lines = $items->map(function (OrderItem $item) use ($seller, &$deliveryClaimed) {
            $order = $item->order;
            $delivery = 0.0;

            if ($order
                && (int) $order->delivery_seller_id === $seller->id
                && ! isset($deliveryClaimed[$order->id])
            ) {
                $delivery = (float) $order->delivery_earning;
                $deliveryClaimed[$order->id] = true;
            }

            return [
                'order_number' => $order?->order_number,
                'goat'         => $item->goat_name,
                'gross'        => (float) $item->line_total,
                'commission'   => (float) $item->commission_amount,
                'delivery'     => $delivery,
                'earning'      => round((float) $item->seller_earning + $delivery, 2),
                'settled'      => $order?->status === 'delivered',
                'paid_out'     => $item->payout_id !== null,
                'date'         => $item->created_at?->toIso8601String(),
            ];
        });

        $unpaid     = $seller->unpaid_earnings;
        $minimum    = (float) Setting::get('min_payout_amount', 0);
        $inFlight   = $seller->pendingPayout();
        $hasDetails = $seller->hasPayoutDetails();
        $railsOpen  = PaymentMethod::payout()->exists();

        return response()->json([
            'data' => [
                'summary' => [
                    'pending'         => $seller->pending_earnings,
                    'lifetime'        => $seller->lifetime_earnings,
                    'unpaid'          => $unpaid,
                    'paid'            => (float) $seller->payouts()->where('status', 'paid')->sum('amount'),
                    'commission_rate' => $seller->effective_commission_rate,
                    'min_payout'      => $minimum,
                    'currency'        => Setting::currencyCode(),
                ],

                // Everything the "get paid" panel needs to decide what to show,
                // worked out here so the storefront never has to guess.
                'payout' => [
                    'accepting'      => $railsOpen,
                    'has_details'    => $hasDetails,
                    'method'         => $seller->payout_method,
                    'method_label'   => $this->payoutMethodLabel($seller->payout_method),
                    'bank_name'      => $seller->payout_bank_name,
                    'needs_bank_name' => $seller->payoutMethodNeedsBankName(),
                    'account_name'   => $seller->payout_account_name,
                    'account_hint'   => $seller->payout_account_number
                        ? '••••'.substr($seller->payout_account_number, -4)
                        : null,
                    'in_progress'    => $inFlight ? new PayoutResource($inFlight) : null,
                    'can_request'    => $railsOpen && $hasDetails && ! $inFlight
                        && $unpaid > 0 && $unpaid >= $minimum,
                    'blocked_reason' => $this->payoutBlockedReason($railsOpen, $hasDetails, $inFlight, $unpaid, $minimum),
                ],

                'lines' => $lines,
            ],
        ]);
    }

    private function payoutMethodLabel(?string $code): ?string
    {
        return $code
            ? PaymentMethod::where('code', $code)->value('name') ?? $code
            : null;
    }

    /** Why the payout button is off, in words the seller can act on. */
    private function payoutBlockedReason(
        bool $railsOpen,
        bool $hasDetails,
        ?Payout $inFlight,
        float $unpaid,
        float $minimum
    ): ?string {
        if (! $railsOpen) {
            return 'Payouts are not open yet. Please check back shortly.';
        }

        if ($inFlight) {
            return 'Payout '.$inFlight->reference.' is already on its way.';
        }

        if (! $hasDetails) {
            return 'Add your payout details to get paid.';
        }

        if ($unpaid <= 0) {
            return 'Nothing to pay out yet — earnings settle once an order is delivered.';
        }

        if ($minimum > 0 && $unpaid < $minimum) {
            return 'You can request a payout once your balance reaches '
                .Setting::currencyCode().' '.number_format($minimum, 2).'.';
        }

        return null;
    }
}
