<?php

namespace App\Http\Resources;

use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\BookingService;
use App\Support\Homestay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isDetail = $request->routeIs('*.rooms.show');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'code' => $this->code,
            'thumbnail' => $this->thumbnail_url,
            'room_type' => $this->room_type,

            'sleeps' => [
                'max' => (int) $this->max_guests,
                // What the nightly rate already covers. A card showing only the
                // maximum promises a room for four at a price that buys a room
                // for two, and the difference turns up at the checkout.
                'included' => (int) $this->base_guests,
                'beds' => (int) $this->beds,
                'private_bathroom' => (bool) $this->has_private_bathroom,
            ],

            'pricing' => [
                'per_night' => (float) $this->price_per_night,
                'extra_guest_fee' => $this->extra_guest_fee !== null
                    ? (float) $this->extra_guest_fee
                    : null,
                'min_nights' => (int) $this->min_nights,
                'max_nights' => (int) $this->max_nights,
            ],

            'amenities' => $this->amenities ?? [],
            'short_description' => $this->short_description,
            'is_featured' => (bool) $this->is_featured,
            'status' => $this->status,

            // Only from the detail endpoint.
            'description' => $this->when($isDetail, $this->description),

            'gallery' => $this->when(
                $isDetail && $this->resource->relationLoaded('images'),
                fn () => $this->gallery(),
            ),

            /*
             * The calendar, sent with the page rather than fetched after it.
             *
             * A date picker that renders empty and then greys out half its days
             * a moment later is a picker somebody has already clicked. The
             * separate availability endpoint exists for re-asking later; this
             * is so the first paint is already true.
             */
            'availability' => $this->when($isDetail, fn () => [
                'taken' => app(BookingService::class)->takenDates($this->resource),
                'earliest_date' => Homestay::earliestDate()->toDateString(),
                'latest_date' => Homestay::latestDate()->toDateString(),
            ]),

            'homestay' => $this->when($isDetail, fn () => Homestay::config()),

            // What a guest can pay with, and which plans each one allows.
            // Public because it is on the room page, which anybody may read
            // before they have signed in.
            'payment_methods' => $this->when($isDetail, fn () => self::paymentOptions()),

            'seo' => [
                'title' => $this->meta_title ?: $this->name,
                'description' => $this->meta_description ?: $this->short_description,
            ],
        ];
    }

    /**
     * The ways a room can be paid for.
     *
     * Read off BookingService so there is exactly one answer to "what may buy a
     * room", and the storefront cannot offer something the server will refuse
     * -- cash on delivery in particular, which is active for goats and must
     * never appear here.
     *
     * @return list<array<string, mixed>>
     */
    public static function paymentOptions(): array
    {
        return BookingService::paymentMethods()
            ->map(fn (PaymentMethod $method) => [
                'code' => $method->code,
                'name' => $method->name,
                'instructions' => $method->instructions,
                'logo' => $method->logo_url,
                'plans' => BookingService::plansFor($method),

                /*
                 * What the advance actually comes to, in the farm's words.
                 *
                 * PaymentMethod::advance_label goes null when a method has
                 * nothing of its own configured, which is right in the admin --
                 * there is nothing to display -- and wrong here. advanceFor()
                 * still falls back to the site-wide share, so the guest is
                 * about to be charged a figure the page was not naming: the
                 * plan read "an advance now" with no indication of how much.
                 */
                'advance_label' => $method->advance_label
                    ?: Setting::get('advance_percent', 30).'%',

                'payee' => $method->payeeDetails(),
            ])
            ->values()
            ->all();
    }

    /**
     * The main photo first, then the gallery, with nothing listed twice.
     *
     * The same rule the goat listing settled on, and for the same reason: the
     * thumbnail is sometimes a copy of the first gallery row and sometimes a
     * photo that appears nowhere else, so keeping the first sighting of each
     * URL is the one behaviour that is right either way.
     *
     * @return list<array{id: int|string, url: string, alt: string|null}>
     */
    private function gallery(): array
    {
        $gallery = [];
        $seen = [];

        if ($this->thumbnail_url) {
            $gallery[] = ['id' => 'main', 'url' => $this->thumbnail_url, 'alt' => $this->name];
            $seen[$this->thumbnail_url] = true;
        }

        foreach ($this->resource->images as $image) {
            if (! $image->url || isset($seen[$image->url])) {
                continue;
            }

            $seen[$image->url] = true;
            $gallery[] = ['id' => $image->id, 'url' => $image->url, 'alt' => $image->alt];
        }

        return $gallery;
    }
}
