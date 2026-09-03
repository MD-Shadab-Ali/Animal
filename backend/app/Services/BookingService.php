<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingNight;
use App\Models\PaymentMethod;
use App\Models\Room;
use App\Models\Setting;
use App\Models\User;
use App\Support\Homestay;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Taking a room off the calendar and giving it to somebody.
 *
 * The interesting half of this class is `hold()`, and everything else exists to
 * make its failure readable. Two guests reaching for the same last room is not
 * an edge case to be defended against with a check-then-insert -- it is the
 * normal state of a festival weekend, and the only thing that can actually
 * refuse the second of them is the database.
 */
class BookingService
{
    /**
     * Which methods a room may be booked with.
     *
     * Cash on delivery drops out of this on its own, without being named: it is
     * flagged `on_delivery_only`, and there is no door for a rider to collect
     * at. What is left is whatever the farm has switched on and can actually
     * take money through -- eSewa and Khalti when their keys are present, and
     * bank transfer when an account has been filled in.
     *
     * Deliberately not PaymentMethod::isCheckoutSelectable(), which lets a
     * delivery-only method stand in when nothing else is active so the goat
     * shop always has some way to place an order. A room with no way to pay for
     * it should simply not be bookable.
     *
     * @return Collection<int, PaymentMethod>
     */
    public static function paymentMethods(): Collection
    {
        return PaymentMethod::query()
            ->where('is_active', true)
            ->where('on_delivery_only', false)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (PaymentMethod $method) => self::plansFor($method) !== [])
            ->values();
    }

    /**
     * The plans this method offers for a stay.
     *
     * PaymentMethod already works out what a method can do; this takes its
     * answer and drops `on_delivery`, the one plan a room cannot be sold on. A
     * method left with nothing is one the farm has configured for collecting
     * cash and nothing else, so it does not appear at all.
     *
     * @return list<string>
     */
    public static function plansFor(PaymentMethod $method): array
    {
        return array_values(array_intersect($method->paymentPlans(), ['full', 'advance']));
    }

    /**
     * Book a room, or explain why not.
     *
     * The whole thing is one transaction. The booking row and the nights it
     * holds are written together or not at all, so a clash on the last night of
     * a stay cannot leave a booking behind holding the first three.
     */
    public function place(Room $room, User $guest, array $data): Booking
    {
        $checkIn = $this->parseDate($data['check_in'] ?? null, 'check_in');
        $checkOut = $this->parseDate($data['check_out'] ?? null, 'check_out');
        $guests = max(1, (int) ($data['guests'] ?? 1));

        $this->assertBookable($room, $checkIn, $checkOut, $guests);

        $method = $this->methodFor($data['payment_method'] ?? null);
        $plan = $this->planFor($method, $data['payment_plan'] ?? null);

        $nights = count(Booking::nightsBetween($checkIn, $checkOut));
        $quote = $room->quote($nights, $guests);

        return DB::transaction(function () use (
            $room, $guest, $data, $checkIn, $checkOut, $guests, $method, $plan, $nights, $quote
        ) {
            $booking = Booking::create([
                'booking_number'     => $this->bookingNumber(),
                'room_id'            => $room->id,
                'user_id'            => $guest->id,

                // Taken from the account, then editable on the form: the person
                // staying is not always the person holding the login.
                'guest_name'         => $data['guest_name'] ?? $guest->name,
                'guest_phone'        => $data['guest_phone'] ?? $guest->phone,
                'guest_email'        => $data['guest_email'] ?? $guest->email,
                'guest_notes'        => $data['guest_notes'] ?? null,

                'check_in'           => $checkIn->toDateString(),
                'check_out'          => $checkOut->toDateString(),
                'nights'             => $nights,
                'guests'             => $guests,

                // The room as it was when it was booked. It can be renamed or
                // re-rated tomorrow; this stay was agreed on these terms.
                'room_name'          => $room->name,
                'room_thumbnail'     => $room->thumbnail,
                'rate_per_night'     => $quote['rate_per_night'],

                'room_charge'        => $quote['room_charge'],
                'extra_guest_charge' => $quote['extra_guest_charge'],
                'discount'           => 0,
                'total'              => $quote['total'],
                'currency'           => Setting::currencyCode(),

                'payment_method'     => $method->code,
                'payment_plan'       => $plan,
                'advance_required'   => $plan === 'advance'
                    ? $method->advanceFor($quote['total'])
                    : null,

                'status'             => 'placed',
            ]);

            return $booking->fresh();
        });
    }

    /**
     * Make `booking_nights` say exactly what this booking is holding.
     *
     * Delete everything it currently holds, then write what it should. A diff
     * would be cleverer and wrong in the one case that matters: a stay moved a
     * day later overlaps itself, and a diff has to reason about that while a
     * clean sweep simply does not.
     *
     * That delete is also what lets a booking be rescheduled onto its own
     * nights at all. It happens inside the caller's transaction, so a clash on
     * the new dates rolls the release back with it -- a booking never loses the
     * nights it had because the ones it wanted were taken.
     *
     * This is the guard. Not the availability check on the room, which is only
     * ever a courtesy to whoever is looking at a page: two requests can both
     * read an empty calendar in the same instant and both conclude they are
     * fine. The unique index on (room_id, night) is the only thing in this
     * system that can tell the second one no, and this is where it does it.
     */
    public function hold(Booking $booking): void
    {
        $wanted = $booking->holdsRoom() ? $booking->occupiedNights() : [];

        BookingNight::where('booking_id', $booking->getKey())->delete();

        if ($wanted === []) {
            return;
        }

        try {
            BookingNight::insert(array_map(fn (string $night) => [
                'booking_id' => $booking->getKey(),
                'room_id'    => $booking->room_id,
                'night'      => $night,
            ], $wanted));
        } catch (UniqueConstraintViolationException) {
            throw $this->roomTaken($booking);
        } catch (QueryException $e) {
            /*
             * Some drivers report the duplicate as a plain query error rather
             * than the typed one above. 23000 is the SQL state for an integrity
             * constraint violation; anything else reaching here is a real fault
             * and has no business being dressed up as a booking clash.
             */
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            throw $this->roomTaken($booking);
        }

        /*
         * The room's page has just started lying about which nights are free.
         *
         * After the commit rather than here, because purging inside the
         * transaction invites the storefront to come back and read the calendar
         * as it was a moment ago -- and it would then cache *that* for the
         * length of the window. Nothing else the shop caches has a correctness
         * cost this direct: a stale price is embarrassing, a stale calendar
         * sells a room twice.
         */
        DB::afterCommit(fn () => app(StorefrontCache::class)->purge([StorefrontCache::ROOMS]));
    }

    /**
     * The dates already taken for this room, for a picker to grey out.
     *
     * Answered from `booking_nights` rather than by walking the bookings,
     * because that is the table the constraint is on -- so what a guest is
     * shown and what the database will accept cannot disagree.
     *
     * @return list<string> dates as Y-m-d
     */
    public function takenDates(Room $room, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        return $room->takenNightsBetween(
            $from ?? CarbonImmutable::today(),
            $to ?? Homestay::latestDate()->addDay(),
        );
    }

    /**
     * Everything that has to be true before a room can be held.
     *
     * Checked here as well as in the browser, because the browser is where a
     * guest chooses dates and not where the farm has to keep a bed free.
     */
    private function assertBookable(Room $room, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $guests): void
    {
        if (! Homestay::isEnabled()) {
            throw ValidationException::withMessages([
                'room' => ['Rooms are not being let at the moment.'],
            ]);
        }

        if ($room->status !== 'published') {
            throw ValidationException::withMessages([
                'room' => ['That room is not available to book.'],
            ]);
        }

        if ($checkOut->lte($checkIn)) {
            throw ValidationException::withMessages([
                'check_out' => ['The day you leave has to be after the day you arrive.'],
            ]);
        }

        if ($checkIn->lt(Homestay::earliestDate())) {
            throw ValidationException::withMessages([
                'check_in' => ['We need time to make the room up, so the earliest we can take you is '
                    .Homestay::earliestDate()->format('j M Y').'.'],
            ]);
        }

        if ($checkOut->gt(Homestay::latestDate())) {
            throw ValidationException::withMessages([
                'check_out' => ['We are only taking bookings up to '
                    .Homestay::latestDate()->format('j M Y').' for now. Call us to arrange a later stay.'],
            ]);
        }

        $nights = count(Booking::nightsBetween($checkIn, $checkOut));

        if ($nights < (int) $room->min_nights) {
            throw ValidationException::withMessages([
                'check_out' => ['This room is let for at least '
                    .$room->min_nights.' night'.((int) $room->min_nights === 1 ? '' : 's').' at a time.'],
            ]);
        }

        if ($nights > (int) $room->max_nights) {
            throw ValidationException::withMessages([
                'check_out' => ['This room can be booked for at most '
                    .$room->max_nights.' nights at a time. Call us for a longer stay.'],
            ]);
        }

        if ($guests > (int) $room->max_guests) {
            throw ValidationException::withMessages([
                'guests' => ['This room sleeps '.$room->max_guests
                    .' at most. Book a second room, or call us.'],
            ]);
        }

        /*
         * The courteous check, and it is only that. It exists so the common
         * case -- somebody picking dates that were already visibly taken -- is
         * refused with a sentence about those dates rather than with a
         * duplicate-key error. It cannot make anything safe, because the row
         * that would conflict need not exist yet at the instant it runs.
         * See hold().
         */
        if (! $room->isFreeBetween($checkIn, $checkOut)) {
            throw ValidationException::withMessages([
                // "Available", not "free". In a sentence a guest reaches while
                // spending money, "free" is read as costing nothing before it
                // is read as unoccupied.
                'check_in' => ['Some of those nights are already taken. '
                    .'Please pick dates that are still available.'],
            ]);
        }
    }

    private function methodFor(?string $code): PaymentMethod
    {
        $method = self::paymentMethods()->firstWhere('code', $code);

        if (! $method) {
            throw ValidationException::withMessages([
                'payment_method' => ['That is not a way to pay for a room.'],
            ]);
        }

        return $method;
    }

    private function planFor(PaymentMethod $method, ?string $plan): string
    {
        $offered = self::plansFor($method);

        if ($plan === null) {
            return $offered[0];
        }

        if (! in_array($plan, $offered, true)) {
            throw ValidationException::withMessages([
                'payment_plan' => ['That is not an option for '.$method->name.'.'],
            ]);
        }

        return $plan;
    }

    private function parseDate(mixed $value, string $field): CarbonImmutable
    {
        if (blank($value)) {
            throw ValidationException::withMessages([
                $field => ['Please choose the dates of your stay.'],
            ]);
        }

        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => ['That is not a date we can read.'],
            ]);
        }
    }

    /**
     * What a guest is told when the room went while they were deciding.
     *
     * Names the room rather than the constraint. "Duplicate entry for key
     * booking_nights_room_id_night_unique" is exactly true and no use at all to
     * somebody trying to find a bed.
     */
    private function roomTaken(Booking $booking): ValidationException
    {
        return ValidationException::withMessages([
            'check_in' => [$booking->room_name.' has just been taken for some of those nights. '
                .'Nothing has been charged -- please pick other dates.'],
        ]);
    }

    private function bookingNumber(): string
    {
        do {
            $number = 'BK-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (Booking::where('booking_number', $number)->exists());

        return $number;
    }
}
