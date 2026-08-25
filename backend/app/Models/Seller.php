<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Seller extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'farm_name', 'slug', 'bio', 'logo', 'banner',
        'contact_phone', 'contact_email', 'address_line', 'area', 'city', 'postal_code',
        'status', 'national_id', 'id_document', 'trade_licence', 'review_note',
        'approved_at', 'approved_by',
        'commission_rate', 'payout_method', 'payout_bank_name',
        'payout_account_name', 'payout_account_number',
    ];

    protected function casts(): array
    {
        return [
            'approved_at'     => 'datetime',
            'commission_rate' => 'decimal:2',
        ];
    }

    /*
     * Note: identity and payout fields are deliberately NOT in $hidden.
     * $hidden strips them from attributesToArray(), which is what Filament fills
     * its forms from — hiding them here blanks them in the admin panel, where
     * staff need them to vet the seller. Exposure is controlled where it belongs,
     * in the API resources: SellerResource (public) omits them entirely, and
     * SellerProfileResource (the owner's own view) masks the account number.
     */

    protected static function booted(): void
    {
        static::saving(function (self $seller) {
            if (blank($seller->slug)) {
                $seller->slug = static::uniqueSlug($seller->farm_name, $seller->getKey());
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'seller';
        $slug = $base;
        $suffix = 1;

        $taken = fn (string $candidate): bool => static::withTrashed()
            ->where('slug', $candidate)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        while ($taken($slug)) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function goats(): HasMany
    {
        return $this->hasMany(Goat::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Orders this seller delivered, and therefore earns the delivery charge on. */
    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'delivery_seller_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(Review::class, Goat::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** Money can only be sent once we know the rail and the account. */
    public function hasPayoutDetails(): bool
    {
        if (blank($this->payout_method)
            || blank($this->payout_account_name)
            || blank($this->payout_account_number)
        ) {
            return false;
        }

        // A bank account number is not enough to send to on its own, so methods
        // that are bank transfers say so and the bank name becomes mandatory.
        return ! $this->payoutMethodNeedsBankName() || filled($this->payout_bank_name);
    }

    public function payoutMethodNeedsBankName(): bool
    {
        return filled($this->payout_method)
            && (bool) PaymentMethod::where('code', $this->payout_method)->value('requires_bank_name');
    }

    /** The payout already asked for and not yet settled, if there is one. */
    public function pendingPayout(): ?Payout
    {
        return $this->payouts()
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->first();
    }

    /** Their own rate if set, otherwise the platform default. */
    public function getEffectiveCommissionRateAttribute(): float
    {
        return (float) ($this->commission_rate ?? Setting::get('default_commission_rate', 10));
    }

    /** Earned on delivered orders but not yet included in a payout. */
    public function getUnpaidEarningsAttribute(): float
    {
        $goats = (float) $this->orderItems()
            ->whereNull('payout_id')
            ->whereHas('order', fn ($query) => $query->where('status', 'delivered'))
            ->sum('seller_earning');

        $delivery = (float) $this->deliveryOrders()
            ->where('status', 'delivered')
            ->whereNull('delivery_payout_id')
            ->sum('delivery_earning');

        return round($goats + $delivery, 2);
    }

    /**
     * Earned, not merely sold. Counting undelivered orders here made a pending
     * sale look like money the seller already had.
     */
    public function getLifetimeEarningsAttribute(): float
    {
        $goats = (float) $this->orderItems()
            ->whereHas('order', fn ($query) => $query->where('status', 'delivered'))
            ->sum('seller_earning');

        $delivery = (float) $this->deliveryOrders()->where('status', 'delivered')->sum('delivery_earning');

        return round($goats + $delivery, 2);
    }

    /** Sold but not yet delivered, so not yet earned. */
    public function getPendingEarningsAttribute(): float
    {
        $goats = (float) $this->orderItems()
            ->whereHas('order', fn ($query) => $query
                ->whereNot('status', 'cancelled')
                ->whereNot('status', 'delivered'))
            ->sum('seller_earning');

        $delivery = (float) $this->deliveryOrders()
            ->whereNot('status', 'cancelled')
            ->whereNot('status', 'delivered')
            ->sum('delivery_earning');

        return round($goats + $delivery, 2);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/'.$this->logo) : null;
    }

    public function getBannerUrlAttribute(): ?string
    {
        return $this->banner ? asset('storage/'.$this->banner) : null;
    }
}
