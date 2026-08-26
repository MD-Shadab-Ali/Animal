<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = ['cart_id', 'goat_id', 'quantity', 'weight_kg'];

    protected function casts(): array
    {
        return ['weight_kg' => 'decimal:2'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function goat(): BelongsTo
    {
        return $this->belongsTo(Goat::class);
    }

    /**
     * What this line costs each.
     *
     * A listing sold by the kilo is priced off the weight the buyer chose,
     * not off the listing's own weight -- that is the whole point of the line
     * carrying a weight at all.
     */
    public function getUnitPriceAttribute(): float
    {
        $goat = $this->goat;

        if (! $goat) {
            return 0.0;
        }

        return $goat->is_weight_priced
            ? $goat->priceForWeight((float) $this->weight_kg)
            : (float) $goat->effective_price;
    }

    public function getLineTotalAttribute(): float
    {
        return round($this->unit_price * $this->quantity, 2);
    }
}
