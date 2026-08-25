<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'goat_id', 'seller_id', 'seller_name',
        'goat_name', 'goat_sku', 'goat_thumbnail',
        'unit_price', 'quantity', 'line_total',
        'commission_rate', 'commission_amount', 'seller_earning', 'payout_id',
        'fulfilment_status', 'fulfilment_note', 'fulfilment_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'        => 'decimal:2',
            'line_total'        => 'decimal:2',
            'commission_rate'   => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'seller_earning'    => 'decimal:2',
            'fulfilment_updated_at' => 'datetime',
        ];
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
