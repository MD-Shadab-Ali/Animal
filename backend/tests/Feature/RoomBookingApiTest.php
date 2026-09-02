<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingNight;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Room;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\Homestay;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The homestay as the storefront sees it.
 *
 * Reading about a room is public and booking one is not, and the line between
 * those two is most of what these check -- along with the thing every layer of
 * this feature has to agree on: the same room cannot be sold to two people, no
 * matter which door the second one comes through.
 */
class RoomBookingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $guest;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->guest = User::where('role', 'customer')->firstOrFail();

        $this->room = Room::create([
            'name' => 'Terrace Room',
            'room_type' => 'Double',
            'max_guests' => 4,
            'base_guests' => 2,
            'price_per_night' => 3000,
            'extra_guest_fee' => 500,
            'status' => 'published',
            'short_description' => 'Looks over the valley.',
        ]);

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
    | Reading about a room
    |--------------------------------------------------------------------------
    */

    /** Anyone may read the rooms, signed in or not. */
    public function test_the_room_list_is_public(): void
    {
        $rooms = $this->getJson('/api/v1/rooms')->assertOk()->json('data');

        $this->assertSame('Terrace Room', $rooms[0]['name']);
        // JSON has no way to say 3000.0, so it arrives as 3000.
        $this->assertSame(3000.0, (float) $rooms[0]['pricing']['per_night']);
        // Both numbers, because the maximum alone promises four beds at a price
        // that only buys two.
        $this->assertSame(4, $rooms[0]['sleeps']['max']);
        $this->assertSame(2, $rooms[0]['sleeps']['included']);
    }

    /** A draft room is not on the shop floor. */
    public function test_an_unpublished_room_is_not_listed_or_readable(): void
    {
        $this->room->update(['status' => 'draft']);

        $this->assertSame([], $this->getJson('/api/v1/rooms')->assertOk()->json('data'));
        $this->getJson('/api/v1/rooms/'.$this->room->slug)->assertNotFound();
    }

    /**
     * Asking for dates narrows the list to rooms that are actually free.
     *
     * Answered from the same table the unique index sits on, so a room shown
     * here is one the database will accept a booking for.
     */
    public function test_the_list_hides_rooms_taken_on_the_asked_for_nights(): void
    {
        $this->book($this->day(0), $this->day(3));

        $query = http_build_query([
            'check_in' => $this->day(1)->toDateString(),
            'check_out' => $this->day(2)->toDateString(),
        ]);

        $this->assertSame([], $this->getJson('/api/v1/rooms?'.$query)->assertOk()->json('data'));

        // The nights on the far side of the stay are still on offer.
        $free = http_build_query([
            'check_in' => $this->day(3)->toDateString(),
            'check_out' => $this->day(4)->toDateString(),
        ]);

        $this->assertCount(1, $this->getJson('/api/v1/rooms?'.$free)->assertOk()->json('data'));
    }

    /** The detail page arrives with its calendar already true. */
    public function test_the_room_page_carries_the_nights_already_taken(): void
    {
        $this->book($this->day(0), $this->day(2));

        $data = $this->getJson('/api/v1/rooms/'.$this->room->slug)->assertOk()->json('data');

        $this->assertSame([
            $this->day(0)->toDateString(),
            $this->day(1)->toDateString(),
        ], $data['availability']['taken']);

        $this->assertSame(Homestay::earliestDate()->toDateString(), $data['availability']['earliest_date']);
    }

    /**
     * Cash on delivery is never offered for a room.
     *
     * It is active, and it buys goats. There is simply no door for a rider to
     * collect at, so it must not reach this page at all -- an option the server
     * will refuse is worse than one that was never shown.
     */
    public function test_the_room_page_never_offers_cash_on_delivery(): void
    {
        $data = $this->getJson('/api/v1/rooms/'.$this->room->slug)->assertOk()->json('data');

        $codes = array_column($data['payment_methods'], 'code');

        $this->assertNotContains('cod', $codes);
        $this->assertContains('bank_transfer', $codes);

        foreach ($data['payment_methods'] as $method) {
            $this->assertNotContains('on_delivery', $method['plans']);
        }
    }

    /** The picker can re-ask, because a page outlives its first paint. */
    public function test_availability_can_be_asked_for_again(): void
    {
        $this->book($this->day(0), $this->day(2));

        $data = $this->getJson('/api/v1/rooms/'.$this->room->slug.'/availability')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['taken']);
        $this->assertSame(4, $data['max_guests']);
    }

    /*
    |--------------------------------------------------------------------------
    | Booking one
    |--------------------------------------------------------------------------
    */

    /** A room is held for a named person, so it needs an account. */
    public function test_booking_requires_an_account(): void
    {
        $this->postJson('/api/v1/rooms/'.$this->room->slug.'/bookings', $this->payload(
            $this->day(0),
            $this->day(2),
        ))->assertUnauthorized();

        $this->assertSame(0, Booking::count());
    }

    public function test_a_guest_can_book_a_room(): void
    {
        Sanctum::actingAs($this->guest);

        $data = $this->postJson('/api/v1/rooms/'.$this->room->slug.'/bookings', $this->payload(
            $this->day(0),
            $this->day(2),
        ))->assertCreated()->json('data');

        $this->assertSame('placed', $data['status']);
        $this->assertSame(2, $data['stay']['nights']);
        $this->assertSame(6000.0, (float) $data['totals']['total']);
        $this->assertSame('Terrace Room', $data['room']['name']);

        // And the nights are actually held.
        $this->assertSame(2, BookingNight::where('room_id', $this->room->id)->count());
    }

    /** The second guest onto the same nights is turned away. */
    public function test_a_second_guest_cannot_take_the_same_nights(): void
    {
        $this->book($this->day(0), $this->day(3));

        $other = $this->anotherGuest();
        Sanctum::actingAs($other);

        $this->postJson('/api/v1/rooms/'.$this->room->slug.'/bookings', $this->payload(
            $this->day(1),
            $this->day(2),
        ))->assertStatus(422);

        $this->assertSame(1, Booking::count());
        $this->assertSame(3, BookingNight::where('room_id', $this->room->id)->count());
    }

    /** Cash on delivery is refused at the door as well as hidden on the page. */
    public function test_a_room_cannot_be_booked_on_cash_on_delivery(): void
    {
        Sanctum::actingAs($this->guest);

        $this->postJson('/api/v1/rooms/'.$this->room->slug.'/bookings', $this->payload(
            $this->day(0),
            $this->day(2),
            ['payment_method' => 'cod'],
        ))->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    /*
    |--------------------------------------------------------------------------
    | Living with one
    |--------------------------------------------------------------------------
    */

    public function test_a_guest_sees_their_own_bookings(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        Sanctum::actingAs($this->guest);

        $list = $this->getJson('/api/v1/bookings')->assertOk()->json('data');
        $this->assertSame($booking->booking_number, $list[0]['booking_number']);

        $one = $this->getJson('/api/v1/bookings/'.$booking->booking_number)->assertOk()->json('data');
        $this->assertSame('Sita Rai', $one['guest']['name']);
        $this->assertSame(6000.0, (float) $one['totals']['due_now']);
    }

    /**
     * Somebody else's booking is not found rather than forbidden.
     *
     * A 403 would confirm the number exists, which is the one thing worth
     * learning from guessing at booking numbers.
     */
    public function test_another_guest_cannot_read_a_booking(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        Sanctum::actingAs($this->anotherGuest());

        $this->getJson('/api/v1/bookings/'.$booking->booking_number)->assertNotFound();
    }

    /** Cancelling gives the nights straight back to the calendar. */
    public function test_cancelling_frees_the_room(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        Sanctum::actingAs($this->guest);

        $this->postJson('/api/v1/bookings/'.$booking->booking_number.'/cancel')->assertOk();

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(0, BookingNight::where('room_id', $this->room->id)->count());

        // And the room is on offer again for those very nights.
        $query = http_build_query([
            'check_in' => $this->day(0)->toDateString(),
            'check_out' => $this->day(2)->toDateString(),
        ]);

        $this->assertCount(1, $this->getJson('/api/v1/rooms?'.$query)->assertOk()->json('data'));
    }

    /** A stay somebody has already started is a phone call, not a button. */
    public function test_a_started_stay_cannot_be_cancelled(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        app(PaymentService::class)->record($booking, ['amount' => 6000]);
        $booking->fresh()->update(['status' => 'checked_in']);

        Sanctum::actingAs($this->guest);

        $this->postJson('/api/v1/bookings/'.$booking->booking_number.'/cancel')->assertStatus(422);

        $this->assertSame('checked_in', $booking->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Paying for one
    |--------------------------------------------------------------------------
    */

    /**
     * Telling us about a payment is a claim, not a receipt.
     *
     * The booking does not move until staff have seen the money, which is the
     * same bargain an order makes -- through the same service.
     */
    public function test_a_guest_can_say_they_have_paid(): void
    {
        $booking = $this->book($this->day(0), $this->day(2), ['payment_plan' => 'advance']);

        Sanctum::actingAs($this->guest);

        $this->postJson('/api/v1/bookings/'.$booking->booking_number.'/payments', [
            'method' => 'bank_transfer',
            'amount' => 1800,
            'transaction_reference' => 'TXN-114',
        ])->assertCreated();

        $payment = Payment::where('booking_id', $booking->id)->firstOrFail();

        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->order_id);
        // Nothing has moved yet: a claim is not money.
        $this->assertSame('placed', $booking->fresh()->status);

        // Staff see it land, and that is what confirms the room.
        app(PaymentService::class)->confirm($payment);

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    /** One open claim at a time, or staff get two rows for one transfer. */
    public function test_a_second_claim_is_refused_while_one_is_outstanding(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        Sanctum::actingAs($this->guest);

        $body = ['method' => 'bank_transfer', 'amount' => 6000];

        $this->postJson('/api/v1/bookings/'.$booking->booking_number.'/payments', $body)->assertCreated();
        $this->postJson('/api/v1/bookings/'.$booking->booking_number.'/payments', $body)->assertStatus(422);

        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
    }

    /** A cancelled booking can ask for its money back. */
    public function test_a_cancelled_booking_can_ask_for_a_refund(): void
    {
        $booking = $this->book($this->day(0), $this->day(2));

        app(PaymentService::class)->record($booking, ['amount' => 6000]);
        $booking->fresh()->update(['status' => 'cancelled']);

        Sanctum::actingAs($this->guest);

        $this->postJson('/api/v1/bookings/'.$booking->booking_number.'/refunds', [
            'refund_to_name' => 'Sita Rai',
            'refund_to_account' => '9800000000',
        ])->assertCreated();

        $refund = Payment::where('booking_id', $booking->id)->refunds()->firstOrFail();

        $this->assertSame('pending', $refund->status);
        $this->assertSame('6000.00', $refund->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function day(int $offset): CarbonImmutable
    {
        return Homestay::earliestDate()->addDays($offset);
    }

    /**
     * A second customer with an account that actually works.
     *
     * `is_active` has to be said out loud: the factory does not set it, and the
     * `active` middleware turns a token belonging to an inactive account into a
     * 401 -- which looks exactly like a signed-out request and hides whatever
     * the test was really asking about.
     */
    private function anotherGuest(): User
    {
        return User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(CarbonImmutable $checkIn, CarbonImmutable $checkOut, array $overrides = []): array
    {
        return array_merge([
            'check_in'       => $checkIn->toDateString(),
            'check_out'      => $checkOut->toDateString(),
            'guests'         => 2,
            'payment_method' => 'bank_transfer',
            'payment_plan'   => 'full',
            'guest_name'     => 'Sita Rai',
            'guest_phone'    => '+977 9800-222222',
            'guest_email'    => 'sita@example.test',
        ], $overrides);
    }

    private function book(CarbonImmutable $checkIn, CarbonImmutable $checkOut, array $overrides = []): Booking
    {
        Sanctum::actingAs($this->guest);

        $this->postJson(
            '/api/v1/rooms/'.$this->room->slug.'/bookings',
            $this->payload($checkIn, $checkOut, $overrides),
        )->assertCreated();

        return Booking::latest('id')->firstOrFail();
    }
}
