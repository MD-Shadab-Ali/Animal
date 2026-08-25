<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'goat_id', 'user_id', 'order_id', 'rating', 'title', 'comment', 'is_approved',
    ];

    protected function casts(): array
    {
        return ['is_approved' => 'boolean'];
    }

    public function goat(): BelongsTo
    {
        return $this->belongsTo(Goat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('is_approved', true);
    }
}
