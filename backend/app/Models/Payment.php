<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Payment extends Model
{
    protected $fillable = [
        'reference', 'order_id', 'user_id', 'method', 'amount', 'currency',
        'type', 'status', 'source', 'transaction_reference', 'proof', 'note',
        'paid_at', 'confirmed_at', 'confirmed_by', 'created_by',
        'refund_to_name', 'refund_to_account', 'refund_to_bank', 'refund_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'paid_at'      => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public const STATUSES = [
        'pending'   => 'Awaiting check',
        'confirmed' => 'Received',
        'rejected'  => 'Rejected',
    ];

    public const STATUS_COLORS = [
        'pending'   => 'warning',
        'confirmed' => 'success',
        'rejected'  => 'danger',
    ];

    /** The same three states, worded for money travelling the other way. */
    public const REFUND_STATUSES = [
        'pending'   => 'Refund requested',
        'confirmed' => 'Refunded',
        'rejected'  => 'Declined',
    ];

    public const TYPES = [
        'payment' => 'Payment',
        'refund'  => 'Refund',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The customer the money came from. */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The goats this money is for.
     *
     * A payment is against an order, and an order is a list of animals — so
     * "who paid for what" has to be answerable without opening the order.
     */
    public function goats(): Collection
    {
        return $this->order?->items->map(fn (OrderItem $item) => trim(
            $item->goat_name.($item->quantity > 1 ? ' x'.$item->quantity : '')
        )) ?? collect();
    }

    /** The same list, short enough for a table cell. */
    public function getGoatsSummaryAttribute(): string
    {
        $goats = $this->goats();

        return match (true) {
            $goats->isEmpty() => '—',
            $goats->count() === 1 => (string) $goats->first(),
            default => $goats->first().' +'.($goats->count() - 1).' more',
        };
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeAwaitingCheck(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeRefunds(Builder $query): Builder
    {
        return $query->where('type', 'refund');
    }

    public function scopePayments(Builder $query): Builder
    {
        return $query->where('type', 'payment');
    }

    public function isRefund(): bool
    {
        return $this->type === 'refund';
    }

    /** Where the money is going back to, on one line. */
    public function getRefundDestinationAttribute(): ?string
    {
        $parts = array_filter([
            $this->method_label,
            $this->refund_to_bank,
            $this->refund_to_name,
            $this->refund_to_account,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /** Refunds count against the money received, so they carry a minus. */
    public function getSignedAmountAttribute(): float
    {
        return round((float) $this->amount * ($this->type === 'refund' ? -1 : 1), 2);
    }

    /** The receipt the buyer attached, if they attached one. */
    public function getProofUrlAttribute(): ?string
    {
        return $this->proof ? asset('storage/'.$this->proof) : null;
    }

    public function hasProof(): bool
    {
        return filled($this->proof);
    }

    /** Images can be shown inline; a PDF has to be a link. */
    public function proofIsImage(): bool
    {
        return $this->hasProof() && in_array(
            strtolower(pathinfo($this->proof, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            true
        );
    }

    /**
     * When money sent on this rail should land, in the rail's own words.
     *
     * Null when nobody has said, which is a cue to promise nothing rather than
     * to guess.
     */
    public function getArrivalEtaAttribute(): ?string
    {
        return PaymentMethod::where('code', $this->method)->value('refund_eta') ?: null;
    }

    public function getMethodLabelAttribute(): string
    {
        return PaymentMethod::where('code', $this->method)->value('name') ?? $this->method;
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->isRefund()) {
            return self::REFUND_STATUSES[$this->status] ?? $this->status;
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }
}
