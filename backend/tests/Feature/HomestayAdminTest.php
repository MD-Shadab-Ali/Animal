<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Models\Booking;
use App\Models\BookingNight;
use App\Models\PaymentMethod;
use App\Models\Room;
use App\Models\User;
use App\Support\Homestay;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin panel as a second way into the same rules.
 *
 * The point of these is narrow and worth stating: the storefront is not the
 * only thing that can book a room. Staff take stays over the phone and move
 * dates when a guest rings to change plans, and if the admin panel wrote rows
 * directly it would become the one route by which the farm could sell a bed
 * twice -- everything in BookingService bypassed, and nothing to show for it
 * but two people at one door.
 */
class HomestayAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $guest;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->admin = User::where('role', 'admin')->firstOrFail();
        $this->guest = User::where('role', 'customer')->firstOrFail();

        $this->room = Room::create([
            'name' => 'Terrace Room',
            'max_guests' => 4,
            'base_guests' => 2,
            'price_per_night' => 3000,
            'extra_guest_fee' => 500,
            'status' => 'published',
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

    /** Every homestay screen has to actually open. */
    public function test_the_homestay_screens_render(): void
    {
        $booking = $this->bookViaAdmin($this->day(0), $this->day(2));

        foreach ([
            '/admin/rooms',
            '/admin/rooms/create',
            '/admin/rooms/'.$this->room->getKey().'/edit',
            '/admin/bookings',
            '/admin/bookings/create',
            '/admin/bookings/'.$booking->getKey(),
            '/admin/bookings/'.$booking->getKey().'/edit',
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    /**
     * A stay taken over the phone holds its nights like any other.
     *
     * The create page hands the form to BookingService rather than inserting,
     * so everything the storefront gets -- the pricing, the notice period, the
     * held nights -- comes with it.
     */
    public function test_staff_can_take_a_booking_and_it_holds_the_room(): void
    {
        $booking = $this->bookViaAdmin($this->day(0), $this->day(2));

        $this->assertSame('placed', $booking->status);
        $this->assertSame(2, (int) $booking->nights);

        // Priced from the room, not from anything the form sent.
        $this->assertSame('6000.00', $booking->total);
        $this->assertSame('Terrace Room', $booking->room_name);

        $this->assertSame([
            $this->day(0)->toDateString(),
            $this->day(1)->toDateString(),
        ], $this->heldNights());
    }

    /**
     * Staff cannot double-book a room either.
     *
     * What matters here is not the error message; it is whether a second
     * booking exists afterwards. It must not.
     */
    public function test_staff_cannot_book_a_room_that_is_already_taken(): void
    {
        $this->bookViaAdmin($this->day(0), $this->day(3));

        $before = Booking::count();

        Livewire::actingAs($this->admin)
            ->test(CreateBooking::class)
            ->fillForm($this->formData($this->day(1), $this->day(2)))
            ->call('create');

        $this->assertSame($before, Booking::count(), 'a clashing booking was written anyway');
        $this->assertCount(3, $this->heldNights());
    }

    /**
     * Moving the dates moves the nights with them.
     *
     * And recounts them: `nights` is a stored column, and a three-night stay
     * still claiming two is what the guest reads and what staff count beds
     * from.
     */
    public function test_moving_a_booking_in_the_admin_moves_the_nights(): void
    {
        $booking = $this->bookViaAdmin($this->day(0), $this->day(2));

        Livewire::actingAs($this->admin)
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->fillForm([
                'check_in' => $this->day(1)->toDateString(),
                'check_out' => $this->day(4)->toDateString(),
            ])
            ->call('save');

        $booking = $booking->fresh();

        $this->assertSame($this->day(1)->toDateString(), $booking->check_in->toDateString());
        $this->assertSame(3, (int) $booking->nights);
        $this->assertSame([
            $this->day(1)->toDateString(),
            $this->day(2)->toDateString(),
            $this->day(3)->toDateString(),
        ], $this->heldNights());
    }

    /**
     * A clashing edit leaves the booking exactly as it was.
     *
     * This is what the transaction on EditBooking is for. Without it the row
     * would move first and the nights would fail to follow, leaving a stay
     * whose dates and whose held nights disagreed -- and a room bookable on
     * nights a guest believed were theirs.
     */
    public function test_a_clashing_edit_changes_nothing(): void
    {
        $mine = $this->bookViaAdmin($this->day(0), $this->day(2));
        $theirs = $this->bookViaAdmin($this->day(4), $this->day(6));

        Livewire::actingAs($this->admin)
            ->test(EditBooking::class, ['record' => $mine->getRouteKey()])
            ->fillForm([
                'check_in' => $this->day(3)->toDateString(),
                'check_out' => $this->day(5)->toDateString(),
            ])
            ->call('save');

        $mine = $mine->fresh();

        $this->assertSame($this->day(0)->toDateString(), $mine->check_in->toDateString());
        $this->assertSame($this->day(2)->toDateString(), $mine->check_out->toDateString());

        // Both stays still hold precisely the nights they started with.
        $this->assertSame([
            $this->day(0)->toDateString(),
            $this->day(1)->toDateString(),
            $this->day(4)->toDateString(),
            $this->day(5)->toDateString(),
        ], $this->heldNights());

        $this->assertSame(2, (int) $theirs->fresh()->nights);
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

    /** @return list<string> */
    private function heldNights(): array
    {
        return BookingNight::where('room_id', $this->room->id)
            ->orderBy('night')
            ->pluck('night')
            ->map(fn ($night) => CarbonImmutable::parse($night)->toDateString())
            ->all();
    }

    /** @return array<string, mixed> */
    private function formData(CarbonImmutable $checkIn, CarbonImmutable $checkOut): array
    {
        return [
            'room_id'        => $this->room->id,
            'user_id'        => $this->guest->id,
            'check_in'       => $checkIn->toDateString(),
            'check_out'      => $checkOut->toDateString(),
            'guests'         => 2,
            'guest_name'     => 'Sita Rai',
            'guest_phone'    => '+977 9800-222222',
            'guest_email'    => 'sita@example.test',
            'payment_method' => 'bank_transfer',
            'payment_plan'   => 'full',
        ];
    }

    private function bookViaAdmin(CarbonImmutable $checkIn, CarbonImmutable $checkOut): Booking
    {
        Livewire::actingAs($this->admin)
            ->test(CreateBooking::class)
            ->fillForm($this->formData($checkIn, $checkOut))
            ->call('create')
            ->assertHasNoFormErrors();

        return Booking::latest('id')->firstOrFail();
    }
}
