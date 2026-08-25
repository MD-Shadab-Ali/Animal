<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = ['cart_id', 'goat_id', 'quantity'];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function goat(): BelongsTo
    {
        return $this->belongsTo(Goat::class);
    }

    public function getUnitPriceAttribute(): float
    {
        return (float) ($this->goat?->effective_price ?? 0);
    }

    public function getLineTotalAttribute(): float
    {
        return round($this->unit_price * $this->quantity, 2);
    }
}
