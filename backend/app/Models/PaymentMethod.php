<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = [
        'code', 'name', 'instructions', 'logo', 'is_active',
        'requires_advance', 'advance_amount', 'config', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'config'           => 'array',
            'is_active'        => 'boolean',
            'requires_advance' => 'boolean',
            'advance_amount'   => 'decimal:2',
        ];
    }

    protected $hidden = ['config'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/'.$this->logo) : null;
    }
}
