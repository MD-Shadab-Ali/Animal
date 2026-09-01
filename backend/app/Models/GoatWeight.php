<?php

namespace App\Models;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One real animal under a listing, at its real weight.
 *
 * A listing says what kind of goat is on offer; these say which goats there
 * actually are. The weight here has been on a scale -- it is not the range an
 * admin typed, and not a figure worked out from a rate.
 */
class GoatWeight extends Model
{
    protected $fillable = [
        'goat_id', 'weight_kg', 'tag', 'image', 'status', 'sold_at',
        'age_months', 'teeth', 'color', 'health_status',
        'is_vaccinated', 'dewormed_at', 'vet_checked_at', 'notes',
    ];

    /**
     * Every animal is scannable from the moment it is recorded.
     *
     * Generated here rather than left to the caller so that an animal added
     * through the admin, a seeder or a console command all end up with one --
     * a tag printed for a goat with no token would simply never resolve.
     */
    protected static function booted(): void
    {
        static::creating(function (self $weight) {
            $weight->token ??= Str::random(32);
        });
    }

    /** Where a scanned ear tag lands: a page anyone holding the goat can read. */
    public function publicUrl(): string
    {
        return rtrim(config('app.frontend_url'), '/').'/animal/'.$this->token;
    }

    /**
     * The tag itself, as a scannable code.
     *
     * SVG rather than a raster: it stays sharp at whatever size it is printed,
     * which matters for something destined to be stuck on a pen and read by a
     * phone at arm's length -- and the SVG backend needs no PHP extensions, so
     * this cannot fail on a machine without GD or Imagick.
     */
    public function qrSvg(int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(
            // Margin 1: a quiet zone that small still scans, and keeps the
            // code compact enough to sit beside a form field.
            new RendererStyle($size, 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($this->publicUrl());
    }

    /** The same code, ready to drop straight into an img or CSS. */
    public function qrDataUri(int $size = 220): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->qrSvg($size));
    }

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:2',
            'sold_at' => 'datetime',
            'age_months' => 'integer',
            'teeth' => 'integer',
            // Nullable on purpose: null is "nobody has recorded this", which
            // is not the same claim as false.
            'is_vaccinated' => 'boolean',
            'dewormed_at' => 'date',
            'vet_checked_at' => 'date',
        ];
    }

    public function goat(): BelongsTo
    {
        return $this->belongsTo(Goat::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    /**
     * This animal's own photograph, or nothing.
     *
     * Deliberately not falling back to the listing's picture here: the caller
     * decides whether a stand-in is honest in its context.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    /**
     * The tooth count read back as the age it means.
     *
     * Dentition is how a goat's age is actually told: the milk teeth give way
     * to permanent incisors in pairs, roughly a pair a year. Printing the bare
     * number tells a buyer nothing unless they already know the scale, which
     * is the whole reason it is worth printing.
     */
    public function teethLabel(): ?string
    {
        return match (true) {
            $this->teeth === null => null,
            $this->teeth <= 0 => 'Milk teeth — under a year',
            $this->teeth <= 2 => '2 permanent — about 1 to 1½ years',
            $this->teeth <= 4 => '4 permanent — about 2 years',
            $this->teeth <= 6 => '6 permanent — about 2 to 3 years',
            default => 'Full mouth — 3 years or more',
        };
    }

    /**
     * Whether this animal's own record says it was vaccinated.
     *
     * Three-valued, because the listing's single checkbox never was: yes, no,
     * and nobody has written it down for this goat.
     */
    public function vaccinationLabel(): ?string
    {
        return match ($this->is_vaccinated) {
            true => 'Vaccinated',
            false => 'Not vaccinated',
            default => null,
        };
    }

    /** What this particular animal costs, at the listing's rate. */
    public function price(): float
    {
        return $this->goat->priceForWeight((float) $this->weight_kg);
    }

    /** Taken off the slider, because it has been sold. */
    public function markSold(): void
    {
        $this->forceFill(['status' => 'sold', 'sold_at' => now()])->save();
    }

    /** How staff refer to it: the tag if there is one, else the weight. */
    public function label(): string
    {
        $weight = rtrim(rtrim(number_format((float) $this->weight_kg, 2), '0'), '.').' kg';

        return $this->tag ? $this->tag.' · '.$weight : $weight;
    }
}
