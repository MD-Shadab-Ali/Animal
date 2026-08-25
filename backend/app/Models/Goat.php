<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Goat extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'seller_id', 'name', 'slug', 'sku', 'thumbnail',
        'breed', 'age_months', 'weight_kg', 'gender', 'color', 'teeth',
        'health_status', 'is_vaccinated', 'specs',
        'price', 'sale_price', 'stock', 'track_stock',
        'short_description', 'description', 'video_url',
        'status', 'approval_status', 'rejection_reason', 'submitted_at',
        'approved_at', 'approved_by', 'is_featured', 'views', 'sort_order',
        'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'specs'         => 'array',
            'submitted_at'  => 'datetime',
            'approved_at'   => 'datetime',
            'price'         => 'decimal:2',
            'sale_price'    => 'decimal:2',
            'weight_kg'     => 'decimal:2',
            'is_featured'   => 'boolean',
            'is_vaccinated' => 'boolean',
            'track_stock'   => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $goat) {
            if (blank($goat->slug)) {
                $goat->slug = Str::slug($goat->name).'-'.Str::lower(Str::random(4));
            }
            if (blank($goat->sku)) {
                $goat->sku = 'GT-'.Str::upper(Str::random(8));
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(GoatImage::class)->orderBy('sort_order');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->reviews()->where('is_approved', true);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where('approval_status', 'approved')
            // A suspended or unapproved seller's stock drops out of the shop.
            ->where(fn (Builder $q) => $q->whereNull('seller_id')
                ->orWhereHas('seller', fn (Builder $s) => $s->where('status', 'approved')));
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('approval_status', 'pending');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** House stock has no seller behind it. */
    public function getIsHouseStockAttribute(): bool
    {
        return $this->seller_id === null;
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('track_stock', false)->orWhere('stock', '>', 0));
    }

    /** Price the customer actually pays. */
    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->sale_price && $this->sale_price < $this->price
            ? $this->sale_price
            : $this->price);
    }

    public function getIsOnSaleAttribute(): bool
    {
        return (bool) ($this->sale_price && $this->sale_price < $this->price);
    }

    public function getDiscountPercentAttribute(): int
    {
        if (! $this->is_on_sale || (float) $this->price <= 0) {
            return 0;
        }

        return (int) round((($this->price - $this->sale_price) / $this->price) * 100);
    }

    public function getIsAvailableAttribute(): bool
    {
        return $this->status === 'published'
            && $this->approval_status === 'approved'
            && (! $this->track_stock || $this->stock > 0);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail ? asset('storage/'.$this->thumbnail) : null;
    }
}
