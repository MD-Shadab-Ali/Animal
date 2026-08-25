<?php

namespace App\Models;

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
        'subtotal', 'discount', 'delivery_charge', 'total', 'currency',
        'delivery_seller_id', 'delivery_earning', 'delivery_payout_id',
        'payment_method', 'payment_status', 'paid_amount', 'advance_required', 'transaction_id',
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

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    /** What the customer still owes on delivery. */
    public function getBalanceDueAttribute(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
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

    public function isCancellable(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
