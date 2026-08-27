<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'goat_id', 'seller_id', 'seller_name',
        'goat_name', 'goat_sku', 'goat_thumbnail',
        'weight_kg', 'price_per_kg',
        'delivered_weight_kg', 'weighed_at', 'weighed_by',
        'unit_price', 'quantity', 'line_total', 'price_adjustment',
        'commission_rate', 'commission_amount', 'seller_earning', 'payout_id',
        'fulfilment_status', 'fulfilment_note', 'fulfilment_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg'         => 'decimal:2',
            'delivered_weight_kg' => 'decimal:2',
            'weighed_at'        => 'datetime',
            'price_per_kg'      => 'decimal:2',
            'unit_price'        => 'decimal:2',
            'line_total'        => 'decimal:2',
            'price_adjustment'  => 'decimal:2',
            'commission_rate'   => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'seller_earning'    => 'decimal:2',
            'fulfilment_updated_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The weight that turned up
    |--------------------------------------------------------------------------
    |
    | `weight_kg` is what the buyer ordered and paid for. `delivered_weight_kg`
    | is what the scale said at the door. They differ for real reasons -- a
    | goat sheds gut fill and water on the road, and the two scales are not the
    | same scale -- so both are kept and neither overwrites the other.
    |
    */

    /** True when this line was actually weighed on arrival. */
    public function getWasWeighedAttribute(): bool
    {
        return $this->delivered_weight_kg !== null;
    }

    /** How far off the order it came in, positive for heavier. */
    public function getWeightDeltaAttribute(): ?float
    {
        if (! $this->was_weighed || $this->weight_kg === null) {
            return null;
        }

        return round((float) $this->delivered_weight_kg - (float) $this->weight_kg, 2);
    }

    /** `same`, `increased` or `decreased` -- worked out, never stored. */
    public function getWeightDirectionAttribute(): ?string
    {
        $delta = $this->weight_delta;

        if ($delta === null) {
            return null;
        }

        return match (true) {
            $delta > 0 => 'increased',
            $delta < 0 => 'decreased',
            default    => 'same',
        };
    }

    /** The person who put it on the scale. */
    public function weigher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'weighed_by');
    }

    /** What this line actually comes to once the scale has had its say. */
    public function getChargedLineTotalAttribute(): float
    {
        return round((float) $this->line_total + (float) $this->price_adjustment, 2);
    }

    /**
     * What this line would come to at the weight that turned up.
     *
     * Scaled from the agreed line rather than from the stored rate, which is
     * rounded to two places -- the same reason the listing scales from its
     * asking price instead of multiplying its own rate.
     */
    public function priceAtDeliveredWeight(): ?float
    {
        if (! $this->was_weighed || ! (float) $this->weight_kg) {
            return null;
        }

        return round((float) $this->line_total * (float) $this->delivered_weight_kg / (float) $this->weight_kg, 2);
    }

    /** What the seller of this line can set, in the order they happen. */
    public const FULFILMENT_STATUSES = [
        'pending'     => 'Not started',
        'preparing'   => 'Preparing the animal',
        'ready'       => 'Ready for collection',
        'handed_over' => 'Handed to the courier',
        'cancelled'   => 'Cancelled',
    ];

    public const FULFILMENT_COLORS = [
        'pending'     => 'gray',
        'preparing'   => 'warning',
        'ready'       => 'info',
        'handed_over' => 'success',
        'cancelled'   => 'danger',
    ];

    /** Forward-only: a seller advances a line, never rewinds it. */
    public const SELLER_FLOW = ['pending', 'preparing', 'ready', 'handed_over'];

    public function canAdvanceTo(string $status): bool
    {
        if ($this->fulfilment_status === 'cancelled') {
            return false;
        }

        $from = array_search($this->fulfilment_status, self::SELLER_FLOW, true);
        $to = array_search($status, self::SELLER_FLOW, true);

        return $from !== false && $to !== false && $to > $from;
    }

    public function getFulfilmentLabelAttribute(): string
    {
        return self::FULFILMENT_STATUSES[$this->fulfilment_status] ?? $this->fulfilment_status;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function goat(): BelongsTo
    {
        return $this->belongsTo(Goat::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->goat_thumbnail ? asset('storage/'.$this->goat_thumbnail) : null;
    }
}
