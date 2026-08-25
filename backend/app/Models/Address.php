<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $fillable = [
        'user_id', 'label', 'full_name', 'phone', 'address_line',
        'area', 'city', 'postal_code', 'notes', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Only one default address per customer.
        static::saved(function (self $address) {
            if ($address->is_default) {
                static::where('user_id', $address->user_id)
                    ->whereKeyNot($address->getKey())
                    ->update(['is_default' => false]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
