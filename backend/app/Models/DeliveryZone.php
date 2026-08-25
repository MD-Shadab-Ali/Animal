<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    protected $fillable = [
        'name', 'description', 'charge', 'free_above', 'estimated_time', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'charge'     => 'decimal:2',
            'free_above' => 'decimal:2',
            'is_active'  => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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
