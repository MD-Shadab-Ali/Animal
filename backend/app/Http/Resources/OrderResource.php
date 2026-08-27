<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'order_number'   => $this->order_number,
            'status'         => $this->status,
            'status_label'   => Order::STATUSES[$this->status] ?? $this->status,
            'payment_method' => $this->payment_method,
            'payment_plan'   => $this->payment_plan,
            'payment_status' => $this->payment_status,
            'is_cancellable' => $this->isCancellable(),
            // The buyer can close this themselves by saying it arrived.
            'can_confirm_receipt' => $this->canConfirmReceipt(),

            'customer' => [
                'name'  => $this->customer_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
            ],

            'shipping' => [
                'address_line' => $this->address_line,
                'area'         => $this->area,
                'city'         => $this->city,
                'postal_code'  => $this->postal_code,
                'zone'         => $this->whenLoaded('deliveryZone', fn () => $this->deliveryZone?->name),
                // What the buyer was promised when they chose the zone.
                'estimate'     => $this->delivery_estimate,
                'notes'        => $this->order_notes,
            ],

            'totals' => [
                'subtotal'        => (float) $this->subtotal,
                // What the scale at the door did to the bill. Signed, and kept
                // apart from the subtotal so the agreed figure stays readable.
                'weight_adjustment' => (float) $this->weight_adjustment,
                'discount'        => (float) $this->discount,
                'delivery_charge' => (float) $this->delivery_charge,
                'total'           => (float) $this->total,
                'paid'            => (float) $this->paid_amount,
                'advance_required' => $this->advance_required !== null ? (float) $this->advance_required : null,
                'balance_due'     => $this->balance_due,
                'currency'        => $this->currency,
            ],

            // Everything the buyer needs to actually pay: where to send it, how
            // much is left, and what they have already told us about.
            'payment' => $this->paymentBlock(),
            'refund'  => $this->refundBlock(),

            // Each line is told which order it belongs to, so a delivered
            // order stops saying the goat is still with the courier.
            'items'      => $this->whenLoaded('items', fn () => $this->items
                ->map(fn ($item) => (new OrderItemResource($item))->forOrder($this->status))),
            'history'    => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
            'placed_at'  => $this->created_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }

    /**
     * How this order can be paid for.
     *
     * The methods offered are the active ones an admin has given payee details
     * to, starting with whatever was chosen at checkout. Cash on delivery has
     * no account to send to, so it simply does not appear.
     */
    private function paymentBlock(): array
    {
        // Money in only. A refund request is a pending row too, and counting it
        // here made asking for a refund announce that we were checking an
        // incoming payment on an order the buyer had just cancelled.
        $paymentRows = $this->relationLoaded('payments')
            ? $this->payments->where('type', 'payment')
            : $this->payments()->where('type', 'payment')->get();

        $pending = $paymentRows->where('status', 'pending');

        $payable = PaymentMethod::active()
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$this->payment_method])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (PaymentMethod $method) => $method->isPrepayable())
            ->map(fn (PaymentMethod $method) => [
                'code'         => $method->code,
                'name'         => $method->name,
                'instructions' => $method->instructions,
                'logo'         => $method->logo_url,
                'payee'        => $method->payeeDetails(),
            ])
            ->values();

        $open = ! in_array($this->status, ['cancelled', 'delivered'], true)
            && ! $this->isFullyPaid();

        // Nothing about an incoming payment matters once the order is off.
        $awaitingCheck = $open && $pending->isNotEmpty();

        // When the money is wanted follows the plan the buyer chose, and each
        // plan is asked for exactly what it promised — no more.
        $due = match ($this->payment_plan) {
            // The whole amount, from the moment the order exists.
            'full' => true,

            // "Advance now, rest on delivery" means precisely that: ask for the
            // advance up front, then stop. The remainder is the rider's to
            // collect, so asking for it online the moment the advance clears
            // would be asking the buyer to pay twice over for no reason.
            'advance' => $this->awaiting_advance || $this->status === 'out_for_delivery',

            // Nothing until the goat is actually on its way.
            default => $this->status === 'out_for_delivery',
        };

        return [
            'status'       => $this->payment_status,
            'plan'         => $this->payment_plan,
            'plan_label'   => $this->payment_plan_label,
            'balance_due'  => $this->balance_due,
            // The advance while one is outstanding, the whole balance after.
            'amount_due_now' => $this->amount_due_now,
            'advance_required' => $this->advance_required !== null
                ? (float) $this->advance_required
                : null,
            'awaiting_advance' => $this->awaiting_advance,

            /*
             * What the shop is actually asking for right now, said plainly so
             * the storefront never has to work it out.
             *
             * `awaiting_advance` cannot answer this: it means "the up-front
             * money has not arrived", and a pay-in-full order sets its
             * up-front amount to the whole total — so the flag is true there
             * too. Reading it as "this is an advance" put "Pay your advance,
             * the remaining Rs 0 is due on arrival" on a full payment.
             */
            'due_kind' => match (true) {
                $this->payment_plan === 'advance' && $this->awaiting_advance => 'advance',
                (float) $this->paid_amount > 0                               => 'balance',
                default                                                      => 'full',
            },
            'is_paid'      => $this->isFullyPaid(),

            // Owed now, but there may still be nowhere to send it.
            'is_due'       => $open && $due,
            'can_pay_now'  => $open && $due && ! $awaitingCheck && $payable->isNotEmpty(),
            // Owed, and no admin has set up an account to receive it. Said out
            // loud rather than rendering nothing, which just looks broken.
            'awaiting_setup' => $open && $due && ! $awaitingCheck && $payable->isEmpty(),

            // Sent, and waiting on a person to check it against the account.
            'awaiting_check'  => $awaitingCheck,
            'submitted_amount' => round((float) $pending->sum('amount'), 2),

            // Everything promised up front has been paid; what is left is
            // handed over at the door, so there is nothing to do online.
            'settled_until_delivery' => $open && ! $due && ! $awaitingCheck
                && (float) $this->paid_amount > 0,

            'methods'      => $payable,
            // "What you have paid" means exactly that; refunds have their own
            // panel and would otherwise read as another payment.
            'history'      => PaymentEntryResource::collection($paymentRows->values()),
        ];
    }

    /**
     * Money owed back, and how to ask for it.
     *
     * Only ever populated on a cancelled order the buyer has actually paid
     * something towards — which is precisely the case the storefront used to
     * greet with "nothing has been charged".
     */
    private function refundBlock(): array
    {
        $refunds = $this->relationLoaded('payments')
            ? $this->payments->where('type', 'refund')
            : $this->payments()->where('type', 'refund')->get();

        $open = $refunds->firstWhere('status', 'pending');
        $sent = $refunds->where('status', 'confirmed');

        return [
            'amount'      => $this->refundable_amount,
            // Why anything is owed. A cancelled order and a goat that weighed
            // light are both refunds, but telling a buyer their live order was
            // cancelled would be alarming and untrue.
            'reason'      => $this->status === 'cancelled' ? 'cancelled' : 'overpaid',
            // The rails we can actually send money out on — the same ones
            // seller payouts use, since it is the same direction of travel.
            'methods'     => PaymentMethod::payout()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (PaymentMethod $method) => [
                    'code' => $method->code,
                    'name' => $method->name,
                    'needs_bank_name' => (bool) $method->requires_bank_name,
                ])
                ->values(),
            // Something is owed back and nobody has asked for it yet.
            'can_request' => $this->isRefundable() && ! $open,
            'requested'   => $open !== null,
            'requested_at' => $open?->created_at?->toIso8601String(),
            'sent'        => round((float) $sent->sum('amount'), 2),
            'destination' => $open?->refund_destination ?? $sent->last()?->refund_destination,

            // How long this rail actually takes, and the reference to quote if
            // it has not turned up. Null means nobody has said, so the
            // storefront promises nothing rather than inventing a duration.
            'eta'         => ($open ?? $sent->last())?->arrival_eta,
            'method_label' => ($open ?? $sent->last())?->method_label,
            'reference'   => $sent->last()?->transaction_reference,
            'sent_at'     => $sent->last()?->confirmed_at?->toIso8601String(),
        ];
    }
}
