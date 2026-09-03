<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'goat_id', 'seller_id', 'seller_name',
        'goat_name', 'goat_sku', 'goat_thumbnail',
        'goat_weight_id',
        'weight_kg', 'price_per_kg',
        'delivered_weight_kg', 'weighed_at', 'weighed_by',
        'unit_price', 'quantity', 'line_total', 'price_adjustment',
        'commission_rate', 'commission_amount', 'seller_earning', 'payout_id',
        'fulfilment_status', 'fulfilment_note', 'fulfilment_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:2',
            'delivered_weight_kg' => 'decimal:2',
            'weighed_at' => 'datetime',
            'price_per_kg' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'price_adjustment' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'seller_earning' => 'decimal:2',
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
            default => 'same',
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
        'pending' => 'Not started',
        'preparing' => 'Preparing the animal',
        'ready' => 'Ready for collection',
        'handed_over' => 'Handed to the courier',
        'cancelled' => 'Cancelled',
    ];

    public const FULFILMENT_COLORS = [
        'pending' => 'gray',
        'preparing' => 'warning',
        'ready' => 'info',
        'handed_over' => 'success',
        'cancelled' => 'danger',
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

    /**
     * The actual animal this line is being filled with, once staff have picked
     * one out of the pen. Null while none has been chosen, and on every line
     * whose listing keeps no individual animals.
     */
    public function goatWeight(): BelongsTo
    {
        return $this->belongsTo(GoatWeight::class);
    }

    /**
     * Tie this line to a particular animal and take it off the shelf.
     *
     * Any animal previously assigned to this line goes back to being available
     * first: re-running the Preparing step after a change of mind must not
     * leave the earlier goat marked sold to nobody.
     */
    public function assignAnimal(?GoatWeight $animal): void
    {
        $previous = $this->goatWeight;

        if ($previous && (! $animal || $previous->isNot($animal))) {
            $previous->forceFill(['status' => 'available', 'sold_at' => null])->save();
        }

        /*
         * The animal's weight becomes the line's real weight.
         *
         * I had this the other way round: assignment left the price alone,
         * because a heavier goat would be settled on the scale at delivery.
         * That was wrong for a pool, and collection is what exposed it. A
         * pooled animal has already been on a scale -- that is what its weight
         * column is -- so there is no later reading to wait for, and on a
         * collection order there is no doorstep weigh-in at all. The buyer was
         * ordering 20 kg, paying for 20 kg, and being handed 22.86 kg.
         *
         * Recorded through delivered_weight_kg so the money moves along the one
         * path that already exists for it, rather than a second one that would
         * have to agree with the first for ever.
         */
        $this->forceFill([
            'goat_weight_id' => $animal?->id,
            'delivered_weight_kg' => $animal ? (float) $animal->weight_kg : null,
            'weighed_at' => $animal ? now() : null,
            'weighed_by' => $animal ? auth()->id() : null,
        ])->save();

        $animal?->markSold();
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
