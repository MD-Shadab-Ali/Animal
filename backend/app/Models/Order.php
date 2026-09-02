<?php

namespace App\Models;

use App\Contracts\Payable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Order extends Model implements Payable
{
    use SoftDeletes;

    protected $fillable = [
        'order_number', 'user_id', 'delivery_zone_id', 'coupon_id',
        'customer_name', 'customer_phone', 'customer_email',
        'address_line', 'area', 'city', 'postal_code', 'order_notes',
        'pickup_at',
        'subtotal', 'weight_adjustment', 'discount', 'delivery_charge', 'delivery_estimate', 'total', 'currency',
        'delivery_seller_id', 'delivery_earning', 'delivery_payout_id',
        'payment_method', 'payment_plan', 'payment_status', 'paid_amount',
        'advance_required', 'transaction_id',
        'status', 'admin_note', 'delivered_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'delivery_earning' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'advance_required' => 'decimal:2',
            'pickup_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The buyer is coming to fetch this one.
     *
     * Read from the order rather than its zone: a zone can be renamed, retired
     * or deleted, and an order from two Dashains ago still has to be able to
     * say what was agreed.
     */
    public function isPickup(): bool
    {
        return $this->pickup_at !== null;
    }

    /**
     * What the buyer is waiting for, in their words.
     *
     * "Out for delivery" is a van on a road. For someone who agreed to come
     * and collect, the same state means the goat is penned and waiting, and
     * telling them it is on its way would send them home.
     */
    public function buyerStatusLabel(): string
    {
        if ($this->isPickup()) {
            return match ($this->status) {
                'out_for_delivery' => 'Ready to collect',
                'delivered' => 'Collected',
                default => self::STATUSES[$this->status] ?? $this->status,
            };
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    public const STATUSES = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    /**
     * A note to attach to the next status change.
     *
     * Transient, not a column: the history row is where it belongs. Set it
     * before saving and the observer writes it alongside the change, so "the
     * customer said it arrived" is distinguishable from staff clicking the
     * same button.
     */
    public ?string $statusNote = null;

    /**
     * A photo taken as the order moved, handed to the observer the same
     * way the note is: the status change is an `update()` on the order,
     * and neither of these belongs in a column on it.
     */
    public ?string $statusPhoto = null;

    public const PAYMENT_PLANS = [
        'full' => 'Paid in full up front',
        'advance' => 'Advance now, rest on delivery',
        'on_delivery' => 'Pay on delivery',
    ];

    public const STATUS_COLORS = [
        'pending' => 'warning',
        'confirmed' => 'info',
        'processing' => 'primary',
        'out_for_delivery' => 'info',
        'delivered' => 'success',
        'cancelled' => 'danger',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function deliverySeller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'delivery_seller_id');
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest();
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    /** What the customer still owes on delivery. */
    public function getBalanceDueAttribute(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }

    /**
     * The money is all in.
     *
     * A cent of tolerance, because the total and the sum of the payments are
     * two separate decimal roundings and must not disagree over 0.001.
     */
    public function isFullyPaid(): bool
    {
        return (float) $this->paid_amount + 0.01 >= (float) $this->total;
    }

    /**
     * Nothing is handed over before it is paid for.
     *
     * This also protects the seller side of the book: earnings settle on
     * `delivered`, so a delivery recorded against an unpaid order would let a
     * seller draw a payout for money the platform never received.
     */
    public function canBeDelivered(): bool
    {
        return $this->isFullyPaid();
    }

    /**
     * What the buyer owes *right now*.
     *
     * On an advance plan that is the advance until it is covered, and the rest
     * only once the goat is on its way. On the other plans it is simply the
     * outstanding balance.
     */
    public function getAmountDueNowAttribute(): float
    {
        if ($this->payment_plan === 'advance' && $this->awaiting_advance) {
            return round((float) $this->advance_required - (float) $this->paid_amount, 2);
        }

        return $this->balance_due;
    }

    /**
     * Money we are holding that is no longer ours.
     *
     * `paid_amount` is already net of any refund that has gone out, so what is
     * left on a cancelled order is exactly what is owed back.
     */
    /**
     * Money already taken that the order no longer asks for.
     *
     * A goat weighed light at the door re-prices the order downwards, and a
     * buyer who had already paid in full is suddenly in credit. Nothing about
     * that is a cancellation -- the order is going ahead -- so it needs its
     * own answer or the money simply sits there.
     */
    public function getOverpaidAmountAttribute(): float
    {
        if ($this->status === 'cancelled') {
            return 0.0;
        }

        return max(0.0, round((float) $this->paid_amount - (float) $this->total, 2));
    }

    public function getRefundableAmountAttribute(): float
    {
        // A cancelled order owes back everything it ever took.
        if ($this->status === 'cancelled') {
            return round((float) $this->paid_amount, 2);
        }

        // A live one owes back only what it stopped being owed.
        return $this->overpaid_amount;
    }

    public function isRefundable(): bool
    {
        return $this->refundable_amount > 0;
    }

    /** The buyer can be asked for money now rather than at the door. */
    public function expectsPaymentUpFront(): bool
    {
        return in_array($this->payment_plan, ['full', 'advance'], true);
    }

    public function getPaymentPlanLabelAttribute(): string
    {
        return self::PAYMENT_PLANS[$this->payment_plan] ?? $this->payment_plan;
    }

    /** An advance was asked for but has not been received yet. */
    public function getAwaitingAdvanceAttribute(): bool
    {
        return $this->advance_required !== null
            && (float) $this->paid_amount < (float) $this->advance_required;
    }

    /*
    |--------------------------------------------------------------------------
    | Who owns this order's status
    |--------------------------------------------------------------------------
    |
    | An order supplied entirely by one seller is theirs to run: they move it
    | from pending through to delivered and staff only watch. Anything else --
    | house stock involved, or goats from more than one seller -- stays with
    | staff, because no single seller can speak for the whole order.
    |
    */

    /** The seller id if this order came from exactly one seller, otherwise null. */
    public function soleSellerId(): ?int
    {
        // Queried rather than read off the relation on purpose: callers such as
        // the seller's own order list eager-load `items` filtered to that seller,
        // and deciding ownership from a filtered set would call every mixed order
        // seller-managed.
        $sellerIds = OrderItem::where('order_id', $this->getKey())
            ->distinct()
            ->pluck('seller_id');

        // No lines, or any house-stock line, means staff supplied part of it.
        if ($sellerIds->isEmpty() || $sellerIds->contains(null)) {
            return null;
        }

        return $sellerIds->count() === 1 ? (int) $sellerIds->first() : null;
    }

    /** True when a single seller runs this order and staff are read-only. */
    public function isSellerManaged(): bool
    {
        return $this->soleSellerId() !== null;
    }

    public function isManagedBy(?Seller $seller): bool
    {
        return $seller !== null && $this->soleSellerId() === $seller->id;
    }

    /** Staff keep the wheel on house-stock and multi-seller orders. */
    public function isStaffManaged(): bool
    {
        return ! $this->isSellerManaged();
    }

    /** Forward-only progression through the delivery states. */
    public const FLOW = ['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered'];

    /**
     * Lines that were bought at a stated weight.
     *
     * Only these can be checked against a scale at the door: a listing sold as
     * one animal at one price never promised a figure to compare against.
     */
    public function weighedItems(): Collection
    {
        return $this->items->filter(fn (OrderItem $item) => $item->weight_kg !== null)->values();
    }

    /**
     * Re-price every weighed line against what the scale said.
     *
     * Only the goats move. The coupon and the delivery charge were agreed at
     * checkout and are left exactly as they were: re-running those rules here
     * would change terms the buyer never accepted, and a lighter goat could
     * come back with a bigger delivery charge attached.
     *
     * Commission and seller earnings follow the charged figure, because that
     * is what the money actually was -- and they have to be right before the
     * payout settles on delivery.
     *
     * Returns the signed difference to the order total.
     */
    public function applyWeightAdjustments(): float
    {
        $adjustment = 0.0;

        foreach ($this->items as $item) {
            $atDelivered = $item->priceAtDeliveredWeight();

            if ($atDelivered === null) {
                continue;
            }

            $delta = round($atDelivered - (float) $item->line_total, 2);
            $charged = round((float) $item->line_total + $delta, 2);

            $commission = round($charged * ((float) $item->commission_rate / 100), 2);

            $item->forceFill([
                'price_adjustment' => $delta,
                'commission_amount' => $item->seller_id ? $commission : 0,
                'seller_earning' => $item->seller_id ? round($charged - $commission, 2) : 0,
            ])->save();

            $adjustment += $delta;
        }

        $adjustment = round($adjustment, 2);

        $this->forceFill([
            'weight_adjustment' => $adjustment,
            'total' => round((float) $this->subtotal + $adjustment
                - (float) $this->discount + (float) $this->delivery_charge, 2),
        ])->save();

        return $adjustment;
    }

    public function hasWeighedItems(): bool
    {
        return $this->weighedItems()->isNotEmpty();
    }

    /**
     * Whether the scale moved the bill.
     *
     * One rule for every screen that prints a totals breakdown. A card that
     * lists subtotal, discount and delivery beside the total does not add up
     * on a re-weighed order unless this figure is shown between them, so any
     * screen showing those four must ask this before it decides to omit it.
     */
    public function hasWeightAdjustment(): bool
    {
        return abs((float) $this->weight_adjustment) >= 0.005;
    }

    /**
     * The one status this order may move to next, or null if it is finished.
     *
     * Deliberately separate from `canAdvanceTo()`, which allows any forward
     * move: the seller's own screens and the automatic line-driven sync both
     * rely on jumping (all lines handed to the courier moves an order to Out
     * for delivery from wherever it was). This is the stricter rule the admin
     * panel offers a human -- one step, in order, no picking from the middle.
     */
    public function nextStatus(): ?string
    {
        if (in_array($this->status, ['cancelled', 'delivered'], true)) {
            return null;
        }

        $at = array_search($this->status, self::FLOW, true);

        if ($at === false) {
            return null;
        }

        return self::FLOW[$at + 1] ?? null;
    }

    public function canAdvanceTo(string $status): bool
    {
        if (in_array($this->status, ['cancelled', 'delivered'], true)) {
            return false;
        }

        $from = array_search($this->status, self::FLOW, true);
        $to = array_search($status, self::FLOW, true);

        return $from !== false && $to !== false && $to > $from;
    }

    /**
     * The buyer may still call it off.
     *
     * Right up to the handover: a goat is a large purchase and plans change,
     * and until the animal is actually with the buyer there is something to
     * call off. Once delivered there is not — that is a return, not a
     * cancellation, and it is a conversation rather than a button.
     */
    /**
     * Out for delivery, fully paid, and waiting on a person to say it arrived.
     *
     * Nothing will close these on its own and nothing should: money cannot
     * witness a delivery. An order paid in full at checkout has no payment left
     * to trigger the close, so someone who was there has to confirm it — and
     * until they do the seller's earnings never settle, because earnings settle
     * on `delivered`. That makes these worth chasing, not just listing.
     */
    public function scopeAwaitingDeliveryConfirmation(Builder $query): Builder
    {
        return $query->where('status', 'out_for_delivery')
            ->whereColumn('paid_amount', '>=', 'total');
    }

    /**
     * The buyer can tell us it turned up.
     *
     * Only once it is actually on its way, and only when nothing else is
     * outstanding — an order still owing money at the door closes itself when
     * the rider records the cash, so the button would be redundant there and
     * misleading if it failed.
     */
    public function canConfirmReceipt(): bool
    {
        return $this->status === 'out_for_delivery' && $this->canBeDelivered();
    }

    public function isCancellable(): bool
    {
        return ! in_array($this->status, ['delivered', 'cancelled'], true);
    }

    /**
     * The line state that matches an order state.
     *
     * Defined once because it was defined twice: the observer and the seller
     * service each carried their own copy, so a line could say "Preparing the
     * animal" under a timeline still reading Confirmed. "Preparing" on the
     * buyer's timeline is `processing` — confirming an order does not mean
     * anyone has touched the animal yet.
     */
    public static function lineStatusFor(string $status): ?string
    {
        return match ($status) {
            'processing' => 'preparing',
            'out_for_delivery' => 'handed_over',
            'delivered' => 'handed_over',
            default => null,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /*
    |--------------------------------------------------------------------------
    | Payable
    |--------------------------------------------------------------------------
    |
    | PaymentService used to take an Order and nothing else. It now takes
    | anything that answers these, because the farm lets rooms as well as
    | selling goats and there is only one ledger. Nothing about how an order
    | takes money has changed -- this is the same behaviour, named.
    |
    | See App\Contracts\Payable.
    |
    */

    public function paymentForeignKey(): string
    {
        return 'order_id';
    }

    public function payer(): ?User
    {
        return $this->user;
    }

    public function paymentTotal(): float
    {
        return (float) $this->total;
    }

    public function balanceDue(): float
    {
        return $this->balance_due;
    }

    public function amountDueNow(): float
    {
        return $this->amount_due_now;
    }

    public function paymentCurrency(): string
    {
        return (string) $this->currency;
    }

    public function defaultPaymentMethod(): ?string
    {
        return $this->payment_method;
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function refundableAmount(): float
    {
        return $this->refundable_amount;
    }

    public function paymentReference(): string
    {
        return (string) $this->order_number;
    }

    public function cancelledMessage(): string
    {
        return 'This order has been cancelled.';
    }

    public function settledMessage(): string
    {
        return 'This order is already paid in full.';
    }

    public function paymentSubjectNoun(): string
    {
        return 'order';
    }

    public function paymentSubjectPath(): string
    {
        return '/account/orders/'.$this->order_number;
    }

    public function payerName(): string
    {
        return (string) $this->customer_name;
    }

    public function payerEmail(): ?string
    {
        return $this->customer_email;
    }

    public function payerPhone(): ?string
    {
        return $this->customer_phone;
    }

    /** @return list<string> */
    public function paymentSummaryLines(): array
    {
        return $this->goatLines()->all();
    }

    /**
     * The animals on this order, named the way a person would name them.
     *
     * Lived on Payment as `goats()` and reached back through the relation to
     * get here. Moved because a payment no longer necessarily has an order, and
     * "what is on this order" was never the payment's fact to know.
     *
     * @return Collection<int, string>
     */
    public function goatLines(): Collection
    {
        return $this->items->map(fn (OrderItem $item) => trim(
            $item->goat_name.($item->quantity > 1 ? ' x'.$item->quantity : '')
        ));
    }

    /**
     * Move the order on to whatever the money has just unlocked.
     *
     * Lifted out of PaymentService when the service stopped being about orders.
     * The rules are unchanged, and they are order rules through and through:
     * both of them turn on what a *delivery* means, and a room has no delivery.
     *
     * Confirming a payment is staff saying "this really arrived", which is the
     * same act as confirming the order -- so they should not then have to go and
     * say it a second time in a different dropdown.
     */
    public function settleAfterPayment(?Payment $trigger): static
    {
        // A refund is money leaving. Confirming one used to make the order look
        // settled -- paid had just come down to meet the total -- and closed it
        // as delivered on the strength of a payment going the other way.
        if ($trigger?->isRefund()) {
            return $this;
        }

        $order = $this;

        // What the buyer promised up front is in, so the order is no longer
        // merely placed: it is committed, and the goat can be prepared. Only
        // from 'pending' — anything further along has already been moved by
        // staff or the seller, and this must never drag an order backwards.
        if ($order->status === 'pending'
            && (float) $order->paid_amount > 0
            && ! $order->awaiting_advance
        ) {
            $order->update(['status' => 'confirmed']);

            $order = $order->fresh();
        }

        return $order->closeIfSettled();
    }

    /**
     * Money handed over at the door is evidence the goat got there.
     *
     * Only when the money was actually due at the door. An order paid in full
     * up front settled long before the animal moved, so its balance says
     * nothing about whether anything arrived -- a person has to say that, and
     * the buyer has a button for it. Closing those on payment released the
     * seller's earnings for a goat nobody had seen.
     *
     * Only from `out_for_delivery`, for the same reason: paying for a goat
     * still standing on the farm does not deliver it.
     */
    private function closeIfSettled(): static
    {
        if (! Setting::get('auto_deliver_on_payment', true)) {
            return $this;
        }

        // Cash on delivery is collected at the handover, and the last slice of
        // an advance is too. Anything else was paid before the journey began.
        if (! in_array($this->payment_plan, ['on_delivery', 'advance'], true)) {
            return $this;
        }

        if ($this->status !== 'out_for_delivery' || ! $this->isFullyPaid()) {
            return $this;
        }

        $this->update(['status' => 'delivered']);

        return $this->fresh();
    }
}
