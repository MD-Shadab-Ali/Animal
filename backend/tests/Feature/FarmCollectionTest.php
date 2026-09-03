<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSettings;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Support\Pickup;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Buyers who come and fetch the goat themselves.
 *
 * Everything else in the shop is delivery. This is the other way round, and the
 * reason it books an hour rather than accepting a cheerful "I will come by" is
 * that an open invitation is how somebody ends up at a farm gate at dusk with
 * an animal and no way home.
 */
class FarmCollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Goat $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->buyer = User::where('role', 'customer')->firstOrFail();

        $this->goat = Goat::create([
            'category_id' => Category::first()->id,
            'name' => 'Collection Test Goat',
            'gender' => 'male',
            'price' => 40000,
            'stock' => 5,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        // A method that can actually start an order. The gateways need keys
        // this suite has no business knowing about, and none of what is being
        // tested here is about how the money moves.
        PaymentMethod::updateOrCreate(
            ['code' => 'counter'],
            [
                'name' => 'Pay at the farm',
                'is_active' => true,
                'on_delivery_only' => false,
                'supports_payout' => false,
                'requires_advance' => false,
                'payee_account_name' => 'Goat Haven Pvt Ltd',
                'payee_account_number' => '9800000000',
                'sort_order' => 9,
            ]
        );
    }

    private function collectionZone(): DeliveryZone
    {
        return DeliveryZone::where('is_pickup', true)->firstOrFail();
    }

    private function deliveryZone(): DeliveryZone
    {
        return DeliveryZone::where('is_pickup', false)->orderBy('sort_order')->firstOrFail();
    }

    /** A bookable moment: the first day on offer, mid-morning. */
    private function slot(string $time = '10:00'): string
    {
        return Pickup::earliestDate()->toDateString().' '.$time;
    }

    private function place(array $overrides = [])
    {
        // The checkout buys what is in the cart, so there has to be one.
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        return $this->postJson('/api/v1/checkout', array_merge([
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+977 9800-111111',
            'customer_email' => 'rahim@example.test',
            'delivery_zone_id' => $this->collectionZone()->id,
            'payment_method' => 'counter',
        ], $overrides));
    }

    /**
     * Collection asks for a time instead of an address.
     *
     * There is nowhere to deliver to, so demanding a postal code would be
     * asking the buyer for something neither side will ever read.
     */
    public function test_a_collection_order_needs_no_address(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place(['pickup_at' => $this->slot()])->assertCreated();

        $order = Order::latest('id')->firstOrFail();

        $this->assertTrue($order->isPickup());
        $this->assertSame('10:00', $order->pickup_at->format('H:i'));
        $this->assertSame(0.0, (float) $order->delivery_charge);
    }

    /** Choosing collection without saying when is not an order, it is a hope. */
    public function test_collection_without_a_time_is_refused(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place()->assertStatus(422)->assertJsonValidationErrors('pickup_at');
    }

    /**
     * An hour nobody works is refused even when posted directly.
     *
     * The browser draws the picker, but the browser is not the side that has to
     * have somebody at the gate when the buyer arrives.
     */
    public function test_a_time_outside_opening_hours_is_refused(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place(['pickup_at' => $this->slot('03:00')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_at');
    }

    /** Half past ten is not on offer, so it cannot be agreed to. */
    public function test_a_time_between_the_slots_is_refused(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place(['pickup_at' => $this->slot('10:30')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_at');
    }

    /** The goat has to be picked out, checked and tagged before anyone arrives. */
    public function test_a_time_sooner_than_the_notice_we_need_is_refused(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place(['pickup_at' => now()->startOfDay()->addHours(10)->format('Y-m-d H:i')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_at');
    }

    /**
     * A date past the horizon is refused, and told so in those words.
     *
     * A buyer picked a date three months out and was answered with "choose one
     * of the collection times we offer" -- so they went and changed the hour,
     * which was never the problem. Each reason has to name itself or it sends
     * the person to fix the wrong field.
     */
    public function test_a_date_beyond_the_horizon_says_so(): void
    {
        Sanctum::actingAs($this->buyer);

        $tooFar = Pickup::latestDate()->addMonth()->setTime(12, 0);

        $this->place(['pickup_at' => $tooFar->format('Y-m-d H:i')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pickup_at');

        $this->assertStringContainsString(
            'only taking collections up to',
            Pickup::problemWith($tooFar)
        );
    }

    /** Too soon and too late are different problems with different answers. */
    public function test_each_reason_names_itself(): void
    {
        $tooSoon = Pickup::earliestDate()->subDay()->setTime(10, 0);
        $tooFar = Pickup::latestDate()->addDay()->setTime(10, 0);
        $offHour = Pickup::earliestDate()->setTime(10, 30);
        $closed = Pickup::earliestDate()->setTime(3, 0);

        $this->assertStringContainsString('earliest is', Pickup::problemWith($tooSoon));
        $this->assertStringContainsString('up to', Pickup::problemWith($tooFar));
        $this->assertStringContainsString('on the hour', Pickup::problemWith($offHour));
        $this->assertStringContainsString('on the hour', Pickup::problemWith($closed));

        // And a good one has nothing to say.
        $this->assertNull(Pickup::problemWith(Pickup::earliestDate()->setTime(10, 0)));
    }

    /**
     * How far ahead people may book belongs to the farm.
     *
     * It was a constant of mine, and the first buyer to reach past it had no
     * way of knowing where the edge was or that it could move.
     */
    public function test_the_farm_can_open_bookings_further_ahead(): void
    {
        $december = Pickup::latestDate()->addMonths(2)->setTime(12, 0);

        $this->assertNotNull(Pickup::problemWith($december));

        Setting::where('key', 'pickup_horizon_days')->firstOrFail()->update(['value' => '365']);

        $this->assertNull(Pickup::problemWith($december));
    }

    /** Delivery is unchanged: it still wants somewhere to deliver to. */
    public function test_a_delivery_order_still_requires_an_address(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place(['delivery_zone_id' => $this->deliveryZone()->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['address_line', 'city', 'area', 'postal_code']);
    }

    /** And a delivery order is never mistaken for a collection. */
    public function test_a_delivery_order_carries_no_collection_time(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place([
            'delivery_zone_id' => $this->deliveryZone()->id,
            'address_line' => 'House 12',
            'area' => 'Ward 4',
            'city' => 'Kathmandu',
            'postal_code' => '44600',
        ])->assertCreated();

        $order = Order::latest('id')->firstOrFail();

        $this->assertNull($order->pickup_at);
        $this->assertFalse($order->isPickup());
    }

    /**
     * The first zone on offer is a delivery.
     *
     * Fixtures and defaults across the shop reach for it, and coming to fetch
     * the animal yourself has to be a deliberate choice rather than something
     * an order falls into.
     */
    public function test_collection_is_never_the_default_zone(): void
    {
        $this->assertFalse(DeliveryZone::active()->orderBy('sort_order')->firstOrFail()->isPickup());
    }

    /** Nothing to save when there is no address, so nothing is saved. */
    public function test_collection_does_not_write_a_blank_address_book_entry(): void
    {
        Sanctum::actingAs($this->buyer);

        $before = $this->buyer->addresses()->count();

        $this->place(['pickup_at' => $this->slot(), 'save_address' => true])->assertCreated();

        $this->assertSame($before, $this->buyer->fresh()->addresses()->count());
    }

    /** What the buyer needs on the morning: where, when, and who to ring. */
    public function test_the_order_tells_the_buyer_where_and_when(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place(['pickup_at' => $this->slot()])->assertCreated();

        $order = Order::latest('id')->firstOrFail();

        $pickup = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->json('data.pickup');

        $this->assertNotNull($pickup['at']);
        $this->assertSame(Setting::get('contact_address'), $pickup['address']);
    }

    /** A delivery order is not given a collection panel to ignore. */
    public function test_a_delivery_order_has_no_collection_details(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place([
            'delivery_zone_id' => $this->deliveryZone()->id,
            'address_line' => 'House 12',
            'area' => 'Ward 4',
            'city' => 'Kathmandu',
            'postal_code' => '44600',
        ])->assertCreated();

        $order = Order::latest('id')->firstOrFail();

        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonMissingPath('data.pickup');
    }

    /**
     * "Out for delivery" would send a collecting buyer home.
     *
     * The state is the same either way; what it means to the person waiting
     * is not.
     */
    public function test_the_status_is_worded_for_whoever_is_waiting(): void
    {
        $collecting = new Order(['status' => 'out_for_delivery', 'pickup_at' => now()->addDay()]);
        $delivering = new Order(['status' => 'out_for_delivery']);

        $this->assertSame('Ready to collect', $collecting->buyerStatusLabel());
        $this->assertSame('Out for delivery', $delivering->buyerStatusLabel());

        $collected = new Order(['status' => 'delivered', 'pickup_at' => now()->addDay()]);

        $this->assertSame('Collected', $collected->buyerStatusLabel());
    }

    /*
     * The four tests that stood here checked a list of other people's guest
     * houses -- that an empty list showed nothing, that a card carried its
     * photo and its price, that a place with no phone was held back, and that
     * the farm's chosen order was kept.
     *
     * All four are gone with the feature. The farm has rooms of its own now and
     * can hold one, so "where can a buyer sleep" is answered by a booking
     * rather than by a phone number. What replaces them is RoomBookingTest,
     * which has a harder question to answer: not whether a list renders, but
     * whether the same room can be sold to two people at once.
     */

    /** The checkout is told how to draw the picker before anyone picks. */
    public function test_the_checkout_is_given_the_collection_window(): void
    {
        Sanctum::actingAs($this->buyer);

        $data = $this->getJson('/api/v1/checkout/options')->assertOk()->json('data');

        $this->assertContains('10:00', $data['pickup']['slots']);
        $this->assertNotContains('03:00', $data['pickup']['slots']);

        $collection = collect($data['delivery_zones'])->firstWhere('is_pickup', true);

        $this->assertNotNull($collection);
        // JSON has no way to say 0.0, so it arrives as 0.
        $this->assertSame(0.0, (float) $collection['charge']);
    }

    /**
     * The hotel list is reachable in the admin.
     *
     * It was not. The pickup group was never named in the settings page, so it
     * fell to the catch-all that appends unknown groups after SEO with no icon
     * -- present, but at the end of a seven-tab row with nothing to mark it.
     * The farm went looking for somewhere to put hotels, did not find it, and
     * reasonably concluded the feature was missing. An empty list renders
     * nothing, which is right, and indistinguishable from being absent.
     */
    public function test_the_settings_page_offers_the_collection_fields(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManageSettings::class)
            ->assertSee('Farm collection')
            ->assertFormFieldExists('pickup_instructions')
            ->assertFormFieldExists('pickup_opens_at')
            ->assertFormFieldExists('pickup_horizon_days');
    }

    /**
     * The hotel list is gone from the settings, not merely emptied.
     *
     * It was a textarea, then a table of guest houses, and is now neither: the
     * farm lets its own rooms. A setting left behind would be a field somebody
     * could still fill in, whose contents nothing reads.
     */
    public function test_the_old_hotel_list_is_gone(): void
    {
        $this->assertNull(
            Setting::where('key', 'pickup_partners')->first(),
            'the textarea was replaced, and then the replacement was removed too'
        );

        $this->assertFalse(
            Schema::hasTable('stay_partners'),
            'the farm lets its own rooms now; there are no third-party hotels'
        );
    }

    /**
     * Every reply carries a whole order, not a partial one.
     *
     * OrderResource builds keys with whenLoaded, and a key whose relation is
     * missing is not sent empty -- it is not sent at all. The action endpoints
     * loaded only the lines, so pressing "I'm on my way" answered with an order
     * that had no history on it. The page set that as its state, emptied
     * "Updates from the farm", and the goat's photograph vanished until the
     * next full fetch put it back.
     */
    public function test_setting_off_replies_with_the_whole_order(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place(['pickup_at' => $this->slot()])->assertCreated();

        $order = Order::latest('id')->firstOrFail();
        $order->update(['status' => 'confirmed']);
        $order->update(['status' => 'processing']);

        $data = $this->postJson('/api/v1/orders/'.$order->order_number.'/on-my-way')
            ->assertOk()
            ->json('data');

        // The three whenLoaded keys the buyer's page reads straight into state.
        $this->assertArrayHasKey('history', $data, 'the updates panel is built from this');
        $this->assertNotEmpty($data['history']);
        $this->assertArrayHasKey('items', $data);
        $this->assertNotNull($data['shipping']['zone']);
    }

    /** And so does cancelling, which reads the same page afterwards. */
    public function test_cancelling_replies_with_the_whole_order(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->place(['pickup_at' => $this->slot()])->assertCreated();

        $order = Order::latest('id')->firstOrFail();

        $data = $this->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('history', $data);
        $this->assertNotEmpty($data['history']);
    }

    /** A malformed opening time falls back rather than offering no slots at all. */
    public function test_a_broken_opening_time_falls_back(): void
    {
        Setting::where('key', 'pickup_opens_at')->firstOrFail()->update(['value' => 'half seven']);

        $this->assertSame('07:00', Pickup::opensAt());
    }
}
