<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    protected $fillable = [
        'reference', 'seller_id', 'amount', 'currency', 'status',
        'method', 'bank_name', 'account_name', 'account_number',
        'transaction_reference', 'note', 'paid_at', 'created_by',
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

    /** The method's display name; the row stores its code. */
    public function getMethodLabelAttribute(): ?string
    {
        return $this->method
            ? PaymentMethod::where('code', $this->method)->value('name') ?? $this->method
            : null;
    }

    /** Everything staff need to send the money, on one line. */
    public function getDestinationAttribute(): ?string
    {
        $parts = array_filter([
            $this->method_label,
            $this->bank_name,
            $this->account_name,
            $this->account_number,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

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
