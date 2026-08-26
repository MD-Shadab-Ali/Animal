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
        'price_per_kg', 'min_weight_kg', 'max_weight_kg', 'weight_step_kg',
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
            'price_per_kg'  => 'decimal:2',
            'min_weight_kg' => 'decimal:2',
            'max_weight_kg' => 'decimal:2',
            'weight_step_kg' => 'decimal:2',
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

            // The rate is never typed in: an asking price against a weight is
            // already a rate, and letting someone enter a third figure only
            // creates a way for the three to disagree.
            $base = (float) $goat->weight_kg;

            $goat->price_per_kg = $base > 0
                ? round((float) $goat->effective_price / $base, 2)
                : null;
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

    /** Price the customer actually pays, at the weight the listing is advertised at. */
    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->sale_price && $this->sale_price < $this->price
            ? $this->sale_price
            : $this->price);
    }

    /*
    |--------------------------------------------------------------------------
    | Asking for a heavier animal
    |--------------------------------------------------------------------------
    |
    | There is no separate mode for this. A listing already carries a weight
    | and an asking price, and together those are a rate -- 21,000 at 18 kg is
    | 1,166.67 a kilo whether anyone writes it down or not. A seller who can
    | supply heavier animals says how heavy, and the buyer picks from in
    | between. Left blank, the listing behaves exactly as it always has.
    |
    | Stock keeps counting animals rather than weights, so five in stock means
    | five buyers however they split the weights between them.
    |
    */

    /** True when the buyer has a weight to choose rather than just the one. */
    public function getIsWeightPricedAttribute(): bool
    {
        return $this->anchor_weight > 0
            && $this->effective_price > 0
            && $this->heaviest_weight > $this->lightest_weight;
    }

    /**
     * The weight the asking price belongs to, and the weight the steps line up
     * against.
     *
     * Anchoring the grid here rather than on the minimum keeps the advertised
     * weight selectable. A listing advertised at 41 kg offering 20 kg upward in
     * 2 kg steps would otherwise run 20, 22 ... 40, 42 -- every stop but the
     * one the buyer came for.
     */
    public function getAnchorWeightAttribute(): float
    {
        return (float) $this->weight_kg;
    }

    public function getWeightStepAttribute(): float
    {
        $step = (float) ($this->weight_step_kg ?: 1);

        return $step > 0 ? $step : 1.0;
    }

    /** The lightest weight actually offered, snapped onto the step grid. */
    public function getLightestWeightAttribute(): float
    {
        $anchor = $this->anchor_weight;
        $floor  = (float) ($this->min_weight_kg ?: $this->weight_kg);

        if ($floor >= $anchor) {
            return $anchor;
        }

        // The lowest stop at or above the seller's floor.
        $steps = floor(($anchor - $floor) / $this->weight_step + 0.000001);

        return round($anchor - $steps * $this->weight_step, 2);
    }

    /** The heaviest weight actually offered, snapped onto the step grid. */
    public function getHeaviestWeightAttribute(): float
    {
        $anchor  = $this->anchor_weight;
        $ceiling = (float) ($this->max_weight_kg ?: $this->weight_kg);

        if ($ceiling <= $anchor) {
            return $anchor;
        }

        // The highest stop at or below the seller's ceiling. A 41 kg anchor
        // stepping by 2 up to a stated 60 stops at 59, because 60 is not a
        // place the selector can land.
        $steps = floor(($ceiling - $anchor) / $this->weight_step + 0.000001);

        return round($anchor + $steps * $this->weight_step, 2);
    }

    /**
     * What the buyer pays for a given weight.
     *
     * Scaled from the asking price against the weight it belongs to, rather
     * than multiplied by the stored rate, which is rounded to two places.
     * Going through the rate would put 30,000.05 on a listing advertised at
     * 30,000, and the advertised figure has to be the one the buyer is charged.
     */
    public function priceForWeight(?float $kg): float
    {
        $base = $this->effective_price;

        if (! $this->is_weight_priced || $kg === null || $this->anchor_weight <= 0) {
            return $base;
        }

        return round($base * (float) $kg / $this->anchor_weight, 2);
    }

    /** The dearest the listing can come to, for a "from X to Y" line. */
    public function getHeaviestPriceAttribute(): float
    {
        return $this->priceForWeight($this->heaviest_weight);
    }

    /**
     * Whether a weight is one the seller actually offers.
     *
     * Checked on the way into the cart and again at checkout, because the
     * seller can narrow their range while a cart is sitting there.
     */
    public function isWeightAllowed(?float $kg): bool
    {
        if (! $this->is_weight_priced) {
            return true;
        }

        if ($kg === null || $kg <= 0) {
            return false;
        }

        if ($kg < $this->lightest_weight || $kg > $this->heaviest_weight) {
            return false;
        }

        // Has to land on a stop the selector could reach. Measured from the
        // advertised weight and compared in whole grams, so a 0.5 kg step does
        // not fail on binary float dust.
        $offset = round(($kg - $this->anchor_weight) * 1000);

        return $offset % round($this->weight_step * 1000) === 0;
    }

    /** Snap a requested weight onto the nearest weight actually offered. */
    public function normaliseWeight(?float $kg): float
    {
        if (! $this->is_weight_priced) {
            return (float) $this->weight_kg;
        }

        $anchor = $this->anchor_weight;
        $step   = $this->weight_step;

        $kg = $anchor + round(((float) $kg - $anchor) / $step) * $step;

        return round(min(max($kg, $this->lightest_weight), $this->heaviest_weight), 2);
    }

    /** Every weight the buyer may choose, for the selector on the page. */
    public function weightOptions(): array
    {
        if (! $this->is_weight_priced) {
            return [];
        }

        $step = $this->weight_step;
        $max  = $this->heaviest_weight;

        $options = [];

        // Capped so a 1 kg step across a huge range cannot flood the payload.
        for ($kg = $this->lightest_weight; $kg <= $max + 0.001 && count($options) < 200; $kg += $step) {
            $rounded = round($kg, 2);

            $options[] = [
                'weight_kg' => $rounded,
                'price'     => $this->priceForWeight($rounded),
            ];
        }

        return $options;
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
