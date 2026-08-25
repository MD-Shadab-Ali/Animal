<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'description', 'type', 'value', 'min_order_amount', 'max_discount',
        'usage_limit', 'usage_limit_per_user', 'used_count',
        'starts_at', 'expires_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value'            => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'max_discount'     => 'decimal:2',
            'starts_at'        => 'datetime',
            'expires_at'       => 'datetime',
            'is_active'        => 'boolean',
        ];
    }

    public function isRedeemable(float $subtotal, ?int $userId = null): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }
        if ($this->min_order_amount !== null && $subtotal < (float) $this->min_order_amount) {
            return false;
        }
        if ($userId && $this->usage_limit_per_user !== null) {
            $used = Order::where('user_id', $userId)
                ->where('coupon_id', $this->id)
                ->whereNot('status', 'cancelled')
                ->count();

            if ($used >= $this->usage_limit_per_user) {
                return false;
            }
        }

        return true;
    }

    public function discountFor(float $subtotal): float
    {
        $discount = $this->type === 'percent'
            ? $subtotal * ((float) $this->value / 100)
            : (float) $this->value;

        if ($this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return round(min($discount, $subtotal), 2);
    }
}
