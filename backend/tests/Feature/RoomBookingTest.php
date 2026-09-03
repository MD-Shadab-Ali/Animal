<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingNight;
use App\Models\PaymentMethod;
use App\Models\Room;
use App\Models\Setting;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Support\Homestay;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The farm's own rooms, and the one thing that must never happen to them.
 *
 * Nearly every test below is about a single sentence: two people cannot be
 * given the same bed on the same night. It is worth this much attention because
 * it is the failure with no recovery -- a goat oversold can be replaced out of
 * the pen, and a room oversold is somebody standing in a yard at nine at night
 * with nowhere to sleep.
 */
class RoomBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $guest;

    private Room $room;

    private BookingService $bookings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->guest = User::where('role', 'customer')->firstOrFail();
        $this->bookings = app(BookingService::class);

        $this->room = Room::create([
            'name' => 'Terrace Room',
            'room_type' => 'Double',
            'max_guests' => 4,
            'base_guests' => 2,
            'beds' => 1,
            'price_per_night' => 3000,
            'extra_guest_fee' => 500,
            'min_nights' => 1,
            'max_nights' => 14,
            'status' => 'published',
        ]);

        // A way to pay that is not a gateway. The gateways need keys this suite
        // has no business knowing about, and nothing here is about how the
        // money travels -- only about what it unlocks.
        PaymentMethod::updateOrCreate(
            ['code' => 'bank_transfer'],
            [
                'name' => 'Bank transfer',
                'is_active' => true,
                'on_delivery_only' => false,
                'supports_payout' => true,
                'requires_advance' => false,
                'payee_account_name' => 'Goat Haven Pvt Ltd',
                'payee_account_number' => '9800000000',
                'sort_order' => 3,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Which nights a stay actually occupies
    |--------------------------------------------------------------------------
    */

    /**
     * The departure day is not a night.
     *
     * The whole calendar rests on this. A guest arriving on the 4th and leaving
     * on the 6th sleeps twice, and the 6th belongs to whoever arrives that
     * afternoon. Charge for it and every stay costs a night too much; hold it
     * and the room sits empty between two guests who never overlapped.
     */
    public function test_a_stay_holds_every_night_but_the_one_it_leaves_on(): void
    {
        $from = $this->day(0);

        $booking = $this->book($from, $this->day(2));

        $this->assertSame(2, (int) $booking->nights);
        $this->assertSame(
            [$from->toDateString(), $from->addDay()->toDateString()],
            $this->heldNights(),
        );
    }

    /** One guest leaves the morning the next arrives, and both are welcome. */
    public function test_back_to_back_stays_are_allowed(): void
    {
        $this->book($this->day(0), $this->day(2));

        $second = $this->book($this->day(2), $this->day(4));

        $this->assertSame('placed', $second->status);
        $this->assertCount(4, $this->heldNights());
    }

    /*
    |--------------------------------------------------------------------------
    | The room cannot be sold twice
    |--------------------------------------------------------------------------
    */

    /** The obvious case: somebody asks for nights that are visibly taken. */
    public function test_a_clashing_stay_is_refused(): void
    {
        $this->book($this->day(0), $this->day(3));

        $this->expectException(ValidationException::class);

        $this->book($this->day(1), $this->day(2));
    }

    /** Overlapping by a single night at either end is still overlapping. */
    public function test_a_stay_overlapping_by_one_night_is_refused(): void
    {
        $this->book($this->day(2), $this->day(4));

        // Leaves on day 3, so it wants the night of day 2 -- which is taken.
        $this->expectException(ValidationException::class);

        $this->book($this->day(1), $this->day(3));
    }

    /**
     * The refusal is the database's, not the application's.
     *
     * This is the test the whole design exists for, so it is worth being
     * precise about what it proves. Every check in PHP -- the room's
     * availability query, the service's assertBookable -- runs at some instant
     * and describes the world at that instant. Two requests can both run them,
     * both see a free calendar, and both go on to insert, because the row that
     * would have conflicted did not exist while either was looking.
     *
     * So this reaches past all of it and writes the row directly, exactly as a
     * second request would arrive to write it a microsecond after the first.
     * Nothing in PHP is consulted. The unique index on (room_id, night) is the
     * only thing standing there, and it has to be enough.
     */
    public function test_the_database_refuses_a_second_hold_on_a_night(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        $this->expectException(QueryException::class);

        DB::table('booking_nights')->insert([
            'booking_id' => $booking->id,
            'room_id' => $this->room->id,
            'night' => CarbonImmutable::parse($booking->check_in)->toDateString(),
        ]);
    }

    /**
     * A refused booking leaves nothing behind.
     *
     * The nights and the booking row are written in one transaction, so a clash
     * on the last night of a stay must not leave a booking sitting there
     * holding the first three -- a room half-sold to somebody who was told they
     * had not got it.
     */
    public function test_a_refused_booking_writes_nothing(): void
    {
        $this->book($this->day(3), $this->day(5));

        $before = Booking::count();

        try {
            $this->book($this->day(1), $this->day(4));
        } catch (ValidationException) {
            // Expected. What matters is what is left behind afterwards.
        }

        $this->assertSame($before, Booking::count());
        $this->assertCount(2, $this->heldNights());
    }

    /** Two rooms are two calendars; the same nights are free in both. */
    public function test_another_room_is_unaffected(): void
    {
        $other = Room::create([
            'name' => 'Garden Room',
            'max_guests' => 2,
            'base_guests' => 2,
            'price_per_night' => 2000,
            'status' => 'published',
        ]);

        $this->book($this->day(0), $this->day(2));
        $second = $this->book($this->day(0), $this->day(2), room: $other);

        $this->assertSame('placed', $second->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Giving nights back
    |--------------------------------------------------------------------------
    */

    /** A cancelled stay releases its nights, and somebody else can have them. */
    public function test_cancelling_frees_the_nights(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        $booking->update(['status' => 'cancelled']);

        $this->assertSame([], $this->heldNights());

        $replacement = $this->book($this->day(0), $this->day(2));

        $this->assertSame('placed', $replacement->status);
    }

    /**
     * A finished stay keeps its nights.
     *
     * They are in the past and nobody can book them, so releasing them buys
     * nothing -- and it would quietly erase the record of the room having been
     * occupied at all. Only a cancellation means the stay is not happening.
     */
    public function test_a_checked_out_stay_still_holds_its_nights(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        $this->settle($booking);
        $booking->fresh()->update(['status' => 'checked_in']);
        $booking->fresh()->update(['status' => 'checked_out']);

        $this->assertCount(2, $this->heldNights());
    }

    /**
     * A stay moved forward by a day does not collide with itself.
     *
     * The nights are swept and rewritten rather than diffed, and this is the
     * case that pays for it: the old range and the new one overlap, so anything
     * cleverer has to work out that a booking may take the nights it already
     * held.
     */
    public function test_a_booking_can_be_moved_onto_its_own_nights(): void
    {
        $booking = $this->book($this->day(0), $this->day(3));

        $booking->update([
            'check_in' => $this->day(1)->toDateString(),
            'check_out' => $this->day(4)->toDateString(),
        ]);

        $this->assertSame([
            $this->day(1)->toDateString(),
            $this->day(2)->toDateString(),
            $this->day(3)->toDateString(),
        ], $this->heldNights());
    }

    /** Moving a stay onto somebody else's nights is refused like any clash. */
    public function test_a_booking_cannot_be_moved_onto_a_taken_night(): void
    {
        $mine = $this->book($this->day(0), $this->day(2));
        $this->book($this->day(4), $this->day(6));

        $this->expectException(ValidationException::class);

        $mine->update([
            'check_in' => $this->day(3)->toDateString(),
            'check_out' => $this->day(5)->toDateString(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | What the database will not accept at all
    |--------------------------------------------------------------------------
    */

    /**
     * A stay cannot end before it starts.
     *
     * At the database, because `nights` is derived from this pair and a
     * zero-night booking would sail through the pricing as a free room.
     */
    public function test_the_database_refuses_a_backwards_stay(): void
    {
        $this->expectException(QueryException::class);

        Booking::withoutEvents(fn () => Booking::create([
            'booking_number' => 'BK-BACKWARDS',
            'room_id' => $this->room->id,
            'user_id' => $this->guest->id,
            'guest_name' => 'Backwards Guest',
            'guest_phone' => '9800000000',
            'check_in' => $this->day(4)->toDateString(),
            'check_out' => $this->day(2)->toDateString(),
            'nights' => 2,
            'guests' => 1,
            'room_name' => $this->room->name,
            'rate_per_night' => 3000,
            'total' => 6000,
            'payment_method' => 'bank_transfer',
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | The window the farm is willing to take
    |--------------------------------------------------------------------------
    */

    /** A room has to be made up, so tonight is not on offer. */
    public function test_a_stay_before_the_notice_period_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->book(
            Homestay::earliestDate()->subDay(),
            Homestay::earliestDate()->addDay(),
        );
    }

    /** Beyond the horizon a rate is a guess the farm would still have to keep. */
    public function test_a_stay_past_the_horizon_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->book(
            Homestay::latestDate()->subDay(),
            Homestay::latestDate()->addDays(3),
        );
    }

    /** The room says how long it is let for, and it is asked. */
    public function test_a_stay_longer_than_the_room_allows_is_refused(): void
    {
        $this->room->update(['max_nights' => 2]);

        $this->expectException(ValidationException::class);

        $this->book($this->day(0), $this->day(5));
    }

    public function test_a_stay_shorter_than_the_room_allows_is_refused(): void
    {
        $this->room->update(['min_nights' => 2]);

        $this->expectException(ValidationException::class);

        $this->book($this->day(0), $this->day(1));
    }

    /** More heads than beds is refused rather than quietly accommodated. */
    public function test_more_guests_than_the_room_sleeps_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->book($this->day(0), $this->day(2), guests: 5);
    }

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    */

    /** The rate covers two; a third head costs what the farm says it costs. */
    public function test_extra_guests_are_charged_per_night(): void
    {
        $booking = $this->book($this->day(0), $this->day(3), guests: 4);

        // Three nights at 3000, plus two guests over the base at 500 a night.
        $this->assertSame('9000.00', $booking->room_charge);
        $this->assertSame('3000.00', $booking->extra_guest_charge);
        $this->assertSame('12000.00', $booking->total);
    }

    /**
     * Cash on delivery cannot buy a room.
     *
     * Not because it is named and excluded, but because it is flagged
     * `on_delivery_only` and there is no door for a rider to collect at. A room
     * held for somebody who never comes and never paid is a night the farm
     * could not sell to anybody else.
     */
    public function test_a_room_cannot_be_booked_on_cash_on_delivery(): void
    {
        $this->assertNotContains(
            'cod',
            BookingService::paymentMethods()->pluck('code')->all(),
        );

        $this->expectException(ValidationException::class);

        $this->book($this->day(0), $this->day(2), method: 'cod');
    }

    /** A room is never sold on a pay-later plan. */
    public function test_a_booking_is_never_offered_pay_on_arrival(): void
    {
        $bank = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();

        $this->assertSame(['full', 'advance'], BookingService::plansFor($bank));
    }

    /** The advance is the site-wide share unless the method pins its own. */
    public function test_an_advance_plan_asks_for_the_configured_share(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), plan: 'advance');

        // 30% of 6000, from the advance_percent setting.
        $this->assertSame('1800.00', $booking->advance_required);
        $this->assertSame(1800.0, $booking->amount_due_now);
        $this->assertTrue($booking->awaiting_advance);
    }

    /**
     * The advance landing is what confirms the room.
     *
     * The same act as on an order, through the same service: staff saying the
     * money arrived is staff saying the thing is on.
     */
    public function test_paying_the_advance_confirms_the_booking(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), plan: 'advance');

        $this->assertSame('placed', $booking->status);

        app(PaymentService::class)->record($booking, ['amount' => 1800]);

        $booking = $booking->fresh();

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('partially_paid', $booking->payment_status);
        $this->assertSame('1800.00', $booking->paid_amount);
        $this->assertFalse($booking->awaiting_advance);
    }

    /** Part of an advance is not the advance, and confirms nothing. */
    public function test_part_of_an_advance_leaves_the_booking_placed(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), plan: 'advance');

        app(PaymentService::class)->record($booking, ['amount' => 500]);

        $this->assertSame('placed', $booking->fresh()->status);
    }

    /**
     * Settling the balance checks the guest in.
     *
     * The advance plan promises "the rest on arrival", so the rest arriving
     * says they have -- the same reasoning the order applies to cash handed to
     * a rider at a door.
     */
    public function test_settling_the_balance_checks_the_guest_in(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), plan: 'advance');

        app(PaymentService::class)->record($booking, ['amount' => 1800]);
        $this->assertSame('confirmed', $booking->fresh()->status);

        app(PaymentService::class)->record($booking->fresh(), ['amount' => 4200]);

        $booking = $booking->fresh();

        $this->assertSame('checked_in', $booking->status);
        $this->assertNotNull($booking->checked_in_at);
    }

    /**
     * Paying up front holds the room and nothing more.
     *
     * What makes a final payment mean "the guest is here" is when it was due,
     * not that it cleared the bill. A full plan is settled at the moment of
     * booking, weeks before anybody sets off -- so it says nothing at all about
     * arrival, and checking somebody in on it marks them present the instant
     * they finish paying.
     */
    public function test_paying_in_full_up_front_does_not_check_anybody_in(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), plan: 'full');

        app(PaymentService::class)->record($booking, ['amount' => 6000]);

        $booking = $booking->fresh();

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertTrue($booking->isFullyPaid());
        $this->assertNull($booking->checked_in_at);
    }

    /** And the sweep leaves a full-plan booking alone for the same reason. */
    public function test_the_command_leaves_a_full_plan_booking_alone(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), plan: 'full');

        app(PaymentService::class)->record($booking, ['amount' => 6000]);
        $this->artisan('bookings:check-in-arrivals')->assertSuccessful();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    /** Part of the money is not all of it, and holds the room and no more. */
    public function test_a_part_paid_booking_is_not_checked_in(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), plan: 'advance');

        app(PaymentService::class)->record($booking, ['amount' => 1800]);

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNull($booking->fresh()->checked_in_at);
    }

    /**
     * The status alone no longer says who is in the house.
     *
     * An advance stay whose balance is settled today but which starts in three
     * weeks is checked in today, so anything asking "who is sleeping here
     * tonight" has to ask the dates. The admin's in-house filter goes through
     * this scope for exactly that reason.
     */
    public function test_a_future_stay_paid_today_is_not_in_the_house_tonight(): void
    {
        $future = $this->book($this->day(20), $this->day(22), plan: 'advance');
        app(PaymentService::class)->record($future, ['amount' => 6000]);

        $this->assertSame('checked_in', $future->fresh()->status);
        $this->assertSame(0, Booking::inHouse()->count());
    }

    /**
     * The sweep is the net under the live path.
     *
     * A stay settled while automatic check-in was switched off sits at
     * `confirmed` with nothing left to trigger it. Turning the setting back on
     * and running the command picks it up.
     */
    public function test_the_command_catches_a_booking_settled_while_switched_off(): void
    {
        $this->setAutoCheckIn(false);

        $booking = $this->book($this->day(0), $this->day(2), plan: 'advance');
        app(PaymentService::class)->record($booking, ['amount' => 6000]);
        $this->assertSame('confirmed', $booking->fresh()->status);

        $this->setAutoCheckIn(true);
        $this->artisan('bookings:check-in-arrivals')->assertSuccessful();

        $this->assertSame('checked_in', $booking->fresh()->status);
    }

    /** A stay still owing money is not checked in by the sweep either. */
    public function test_the_command_leaves_an_unpaid_booking_alone(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), plan: 'advance');

        app(PaymentService::class)->record($booking, ['amount' => 1800]);

        $this->artisan('bookings:check-in-arrivals')->assertSuccessful();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    /** A farm that would rather hand over keys itself can switch this off. */
    public function test_automatic_check_in_can_be_switched_off(): void
    {
        $this->setAutoCheckIn(false);

        $booking = $this->book($this->day(0), $this->day(2));

        app(PaymentService::class)->record($booking, ['amount' => 6000]);
        $this->artisan('bookings:check-in-arrivals')->assertSuccessful();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    /** A stay does not close with money still outstanding. */
    public function test_an_unpaid_stay_cannot_be_checked_out(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), plan: 'advance');

        app(PaymentService::class)->record($booking, ['amount' => 1800]);
        $booking->fresh()->update(['status' => 'checked_in']);

        $this->expectException(ValidationException::class);

        $booking->fresh()->update(['status' => 'checked_out']);
    }

    /** The ledger carries a room the same way it carries an order. */
    public function test_a_payment_for_a_room_names_the_room(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        $payment = app(PaymentService::class)->record($booking, ['amount' => 6000]);

        $this->assertNull($payment->order_id);
        $this->assertSame($booking->id, $payment->booking_id);
        $this->assertSame('booking', $payment->subject()->paymentSubjectNoun());
        $this->assertStringContainsString('Terrace Room', $payment->goats_summary);
    }

    /** A cancelled booking owes back everything it took. */
    public function test_a_cancelled_booking_is_refundable_in_full(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        app(PaymentService::class)->record($booking, ['amount' => 6000]);

        $booking->fresh()->update(['status' => 'cancelled']);

        $this->assertSame(6000.0, $booking->fresh()->refundable_amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** The nth bookable day, counting from the first one on offer. */
    private function day(int $offset): CarbonImmutable
    {
        return Homestay::earliestDate()->addDays($offset);
    }

    /** The farm's switch for whether settling up checks a guest in. */
    private function setAutoCheckIn(bool $on): void
    {
        Setting::where('key', 'auto_check_in_on_payment')
            ->firstOrFail()
            ->update(['value' => $on ? '1' : '0']);

        // The settings map is cached, and a direct update fires no model event.
        Setting::flushCache();
    }

    /**
     * Every night the test room is holding, in order.
     *
     * Read from `booking_nights` rather than from the bookings, because that is
     * the table the unique index is on -- so a test that passes here is a test
     * about the thing that actually enforces availability.
     *
     * @return list<string>
     */
    private function heldNights(): array
    {
        return BookingNight::where('room_id', $this->room->id)
            ->orderBy('night')
            ->pluck('night')
            ->map(fn ($night) => CarbonImmutable::parse($night)->toDateString())
            ->all();
    }

    private function book(
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
        int $guests = 2,
        string $method = 'bank_transfer',
        ?string $plan = 'full',
        ?Room $room = null,
    ): Booking {
        return $this->bookings->place($room ?? $this->room, $this->guest, [
            'check_in'       => $checkIn->toDateString(),
            'check_out'      => $checkOut->toDateString(),
            'guests'         => $guests,
            'payment_method' => $method,
            'payment_plan'   => $plan,
            'guest_name'     => 'Sita Rai',
            'guest_phone'    => '+977 9800-222222',
            'guest_email'    => 'sita@example.test',
        ]);
    }

    /** Pay a booking off, so it can be moved through the rest of the flow. */
    private function settle(Booking $booking): void
    {
        app(PaymentService::class)->record($booking, ['amount' => (float) $booking->total]);
    }
}
