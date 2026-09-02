<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    protected $fillable = [
        'name', 'description', 'charge', 'free_above', 'estimated_time', 'is_active', 'sort_order', 'is_pickup',
    ];

    protected function casts(): array
    {
        return [
            'charge' => 'decimal:2',
            'free_above' => 'decimal:2',
            'is_active' => 'boolean',
            'is_pickup' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The buyer comes to us instead of the goat going to them.
     *
     * A zone rather than a concept of its own: the checkout already asks how
     * the goat should reach the buyer, and this is one of the answers.
     */
    public function isPickup(): bool
    {
        return (bool) $this->is_pickup;
    }

    /** Delivery cost for a given order subtotal. */
    public function chargeFor(float $subtotal): float
    {
        if ($this->free_above !== null && $subtotal >= (float) $this->free_above) {
            return 0.0;
        }

        return (float) $this->charge;
    }
}
