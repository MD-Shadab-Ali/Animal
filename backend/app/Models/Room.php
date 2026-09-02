<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A room at the farm, which the farm actually has and can let.
 *
 * The shop's other listing is a goat, and the two are more alike than they look
 * -- both are photographed, described, priced, featured and given a page -- so
 * this deliberately reads like Goat wherever it can. Where it stops reading
 * like Goat is stock: a goat is available or it is not, while a room is
 * available *for some nights and not others*, and no column here can say that.
 * Availability is a question about a date range, and `booking_nights` answers
 * it.
 */
class Room extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'code', 'thumbnail',
        'room_type', 'max_guests', 'base_guests', 'beds', 'has_private_bathroom', 'amenities',
        'price_per_night', 'extra_guest_fee', 'min_nights', 'max_nights',
        'short_description', 'description',
        'status', 'is_featured', 'views', 'sort_order',
        'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'price_per_night' => 'decimal:2',
            'extra_guest_fee' => 'decimal:2',
            'has_private_bathroom' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * The column defaults, said again where the rules can actually see them.
     *
     * A database default only appears on a model that has been read back. A
     * room created without these -- from a short admin form, or from a seeder
     * -- carries nulls in memory until something reloads it, and the booking
     * rules cast those to zero. `max_nights` of zero refuses every stay ever
     * proposed, with a message reading "at most  nights", and the room looks
     * broken rather than misconfigured.
     *
     * Kept in step with the migration by hand, which is the price of having
     * them here; every one of these is read by a rule that must not see null.
     */
    protected $attributes = [
        'max_guests' => 2,
        'base_guests' => 2,
        'beds' => 1,
        'has_private_bathroom' => true,
        'min_nights' => 1,
        'max_nights' => 14,
        'status' => 'published',
        'is_featured' => false,
        'views' => 0,
        'sort_order' => 0,
    ];

    /**
     * The slug and the code are derived, not typed.
     *
     * The same bargain the goat listing makes: nobody administering rooms
     * should have to think about URLs, and a room renamed for the season keeps
     * the address anything already links to.
     */
    protected static function booted(): void
    {
        static::saving(function (self $room) {
            if (blank($room->slug)) {
                $room->slug = Str::slug($room->name).'-'.Str::lower(Str::random(4));
            }

            if (blank($room->code)) {
                $room->code = 'RM-'.Str::upper(Str::random(6));
            }

            // A rate covering nobody is a rate for nothing, and the extra-guest
            // arithmetic below would then charge from the first head.
            if ((int) $room->base_guests < 1) {
                $room->base_guests = 1;
            }

            /*
             * Two pairs of settings that can contradict each other, and this is
             * the only place to catch it. A max below the min offers a stay of
             * no permitted length at all, which reads to a guest as a room that
             * is never available and to the farm as a bug in the calendar.
             */
            if ((int) $room->max_guests < (int) $room->base_guests) {
                $room->max_guests = $room->base_guests;
            }

            if ((int) $room->max_nights < (int) $room->min_nights) {
                $room->max_nights = $room->min_nights;
            }
        });
    }

    public function images(): HasMany
    {
        return $this->hasMany(RoomImage::class)->orderBy('sort_order');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Every night this room is spoken for. */
    public function nights(): HasMany
    {
        return $this->hasMany(BookingNight::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /** The order the farm put them in, then oldest first as a tiebreak. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail ? asset('storage/'.$this->thumbnail) : null;
    }

    public function getIsAvailableAttribute(): bool
    {
        return $this->status === 'published';
    }

    /**
     * The nights of this room already taken, between two dates.
     *
     * Half-open at the far end, the same way a stay is: asked about the 4th to
     * the 6th it looks at the 4th and the 5th, because the 6th is a morning the
     * room is free again by. Treating `$to` as inclusive would make every
     * booking appear to clash with the one starting the day it ends.
     *
     * @return list<string> dates as Y-m-d
     */
    public function takenNightsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->nights()
            ->where('night', '>=', $from->toDateString())
            ->where('night', '<', $to->toDateString())
            ->orderBy('night')
            ->pluck('night')
            ->map(fn ($night) => CarbonImmutable::parse($night)->toDateString())
            ->values()
            ->all();
    }

    /**
     * Nothing is booked across these dates -- as far as this instant knows.
     *
     * Worth being exact about what this is for. It is how a page can say "not
     * available" and grey out a date. It is *not* what makes a booking safe:
     * between this returning true and a row being written, somebody else can
     * take the room. The unique index on (room_id, night) is what actually
     * refuses the second one. See BookingService::hold().
     */
    public function isFreeBetween(CarbonImmutable $checkIn, CarbonImmutable $checkOut): bool
    {
        return $this->takenNightsBetween($checkIn, $checkOut) === [];
    }

    /**
     * What a stay of this shape costs, itemised.
     *
     * One method, used by the room page's live price, by the booking as it is
     * placed, and by anything that later has to re-check a total. A second copy
     * of this arithmetic anywhere is a way for the quoted price and the charged
     * price to differ, which is the one difference a guest always notices.
     *
     * @return array{rate_per_night: float, nights: int, room_charge: float, extra_guests: int, extra_guest_charge: float, total: float}
     */
    public function quote(int $nights, int $guests): array
    {
        $nights = max(1, $nights);
        $guests = max(1, $guests);

        $rate = (float) $this->price_per_night;
        $roomCharge = round($rate * $nights, 2);

        // Only heads above what the rate already covers, and only when the farm
        // has actually set a fee. No fee means extra guests are free rather
        // than forbidden -- the room's own max_guests is what forbids.
        $extraGuests = max(0, $guests - (int) $this->base_guests);
        $extraCharge = $this->extra_guest_fee === null
            ? 0.0
            : round((float) $this->extra_guest_fee * $extraGuests * $nights, 2);

        return [
            'rate_per_night'     => $rate,
            'nights'             => $nights,
            'room_charge'        => $roomCharge,
            'extra_guests'       => $extraGuests,
            'extra_guest_charge' => $extraCharge,
            'total'              => round($roomCharge + $extraCharge, 2),
        ];
    }

    /** What the cheapest possible night here costs, for a listing card. */
    public function getFromPriceAttribute(): float
    {
        return (float) $this->price_per_night;
    }
}
