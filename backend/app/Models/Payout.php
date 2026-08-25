<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    protected $fillable = [
        'reference', 'seller_id', 'amount', 'currency', 'status',
        'method', 'transaction_reference', 'note', 'paid_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'  => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public const STATUSES = [
        'pending'    => 'Pending',
        'processing' => 'Processing',
        'paid'       => 'Paid',
        'failed'     => 'Failed',
    ];

    public const STATUS_COLORS = [
        'pending'    => 'warning',
        'processing' => 'info',
        'paid'       => 'success',
        'failed'     => 'danger',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
