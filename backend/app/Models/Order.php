<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number', 'user_id', 'delivery_zone_id', 'coupon_id',
        'customer_name', 'customer_phone', 'customer_email',
        'address_line', 'area', 'city', 'postal_code', 'order_notes',
        'subtotal', 'discount', 'delivery_charge', 'delivery_estimate', 'total', 'currency',
        'delivery_seller_id', 'delivery_earning', 'delivery_payout_id',
        'payment_method', 'payment_plan', 'payment_status', 'paid_amount',
        'advance_required', 'transaction_id',
        'status', 'admin_note', 'delivered_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'        => 'decimal:2',
            'discount'        => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'delivery_earning' => 'decimal:2',
            'total'           => 'decimal:2',
            'paid_amount'     => 'decimal:2',
            'advance_required' => 'decimal:2',
            'delivered_at'    => 'datetime',
            'cancelled_at'    => 'datetime',
        ];
    }

    public const STATUSES = [
        'pending'          => 'Pending',
        'confirmed'        => 'Confirmed',
        'processing'       => 'Processing',
        'out_for_delivery' => 'Out for delivery',
        'delivered'        => 'Delivered',
        'cancelled'        => 'Cancelled',
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

    public const PAYMENT_PLANS = [
        'full'        => 'Paid in full up front',
        'advance'     => 'Advance now, rest on delivery',
        'on_delivery' => 'Pay on delivery',
    ];

    public const STATUS_COLORS = [
        'pending'          => 'warning',
        'confirmed'        => 'info',
        'processing'       => 'primary',
        'out_for_delivery' => 'info',
        'delivered'        => 'success',
        'cancelled'        => 'danger',
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
    public function getRefundableAmountAttribute(): float
    {
        if ($this->status !== 'cancelled') {
            return 0.0;
        }

        return round((float) $this->paid_amount, 2);
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
            'processing'       => 'preparing',
            'out_for_delivery' => 'handed_over',
            'delivered'        => 'handed_over',
            default            => null,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
