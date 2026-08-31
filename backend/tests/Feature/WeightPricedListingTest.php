<?php

namespace Tests\Feature;

use App\Filament\Resources\Goats\Pages\EditGoat;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Seller;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Buying a goat by the kilo.
 *
 * A `per_kg` listing is an offering rather than one animal: the seller names a
 * rate and the weights they can supply between, and each buyer picks the
 * weight they want. Two buyers wanting 25 kg and 37 kg of the same listing is
 * the whole point, so the two have to be able to sit side by side without
 * either one being mistaken for the other.
 */
class WeightPricedListingTest extends TestCase
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

        // Advertised at 18 kg for 21,000, and the seller can go up to 40 kg.
        // That makes the rate 1,166.67 a kilo without anyone entering one.
        $this->goat = Goat::create([
            'category_id'     => Category::first()->id,
            'name'            => 'Barbari Buck',
            'gender'          => 'male',
            'weight_kg'       => 18,
            'price'           => 21000,
            'max_weight_kg'   => 40,
            'weight_step_kg'  => 1,
            'stock'           => 5,
            'track_stock'     => true,
            'status'          => 'published',
            'approval_status' => 'approved',
        ]);
    }

    public function test_the_rate_is_worked_out_from_the_price_and_the_weight(): void
    {
        // Nobody entered 1,166.67 — 21,000 at 18 kg already said it.
        $this->assertSame('1166.67', $this->goat->fresh()->price_per_kg);

        // The advertised price is untouched: it is what the listing sells for
        // at the weight it is advertised at.
        $this->assertSame(21000.0, $this->goat->effective_price);
        $this->assertSame(46666.67, $this->goat->heaviest_price);
    }

    public function test_the_rate_follows_the_price_when_it_changes(): void
    {
        $this->goat->update(['price' => 27000]);

        $this->assertSame('1500.00', $this->goat->fresh()->price_per_kg);
    }

    public function test_price_follows_the_weight_the_buyer_picks(): void
    {
        // Scaled from the asking price, not multiplied by the rounded rate --
        // going through 1,166.67 would put 21,000.06 on the advertised weight.
        $this->assertSame(21000.0, $this->goat->priceForWeight(18));
        $this->assertSame(29166.67, $this->goat->priceForWeight(25));
        $this->assertSame(43166.67, $this->goat->priceForWeight(37));
    }

    public function test_the_detail_endpoint_offers_the_weights_and_their_prices(): void
    {
        $pricing = $this->getJson('/api/v1/goats/'.$this->goat->slug)
            ->assertOk()
            ->json('data.pricing');

        $this->assertTrue($pricing['is_per_kg']);
        // Compared by value: JSON gives back a whole number as an int.
        $this->assertEquals(1166.67, $pricing['price_per_kg']);
        $this->assertEquals(18, $pricing['min_weight_kg']);
        $this->assertEquals(40, $pricing['max_weight_kg']);

        // 18 through 40 inclusive, in whole kilos.
        $this->assertCount(23, $pricing['options']);
        $this->assertEquals(['weight_kg' => 25, 'price' => 29166.67], $pricing['options'][7]);
    }

    public function test_two_weights_of_one_listing_are_two_cart_lines(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 25])->assertCreated();
        $cart = $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 37])
            ->assertCreated()
            ->json('data');

        $this->assertCount(2, $cart['items']);

        $lines = collect($cart['items'])->keyBy('weight_kg');

        // Each line is priced off its own weight, not off the listing's.
        $this->assertEquals(29166.67, $lines[25]['unit_price']);
        $this->assertEquals(43166.67, $lines[37]['unit_price']);
        $this->assertEquals(72333.34, $cart['totals']['subtotal']);
    }

    public function test_adding_the_same_weight_twice_stacks_onto_one_line(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 25])->assertCreated();
        $cart = $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 25])
            ->assertCreated()
            ->json('data');

        $this->assertCount(1, $cart['items']);
        $this->assertSame(2, $cart['items'][0]['quantity']);
    }

    public function test_a_weight_the_seller_does_not_supply_is_refused(): void
    {
        Sanctum::actingAs($this->buyer);

        // Over the top of the range.
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 60])
            ->assertStatus(422)
            ->assertJsonValidationErrors('weight_kg');

        // Under the bottom of it.
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 12])
            ->assertStatus(422)
            ->assertJsonValidationErrors('weight_kg');

        // Between two steps the selector could never stop on.
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 25.5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('weight_kg');
    }

    public function test_every_weight_of_a_listing_draws_on_the_same_animals(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->goat->update(['stock' => 2]);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 25])->assertCreated();
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 30])->assertCreated();

        // Stock counts animals, not weights: a third line is a third goat, and
        // there are only two.
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 37])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }

    public function test_the_order_records_the_weight_that_was_bought(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 37])->assertCreated();

        $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Rahim Uddin',
            'customer_email'   => 'rahim@example.test',
            'area'             => 'Ward 4',
            'postal_code'      => '44600',
            'customer_phone'   => '+977 9800-111111',
            'address_line'     => 'House 12',
            'city'             => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method'   => 'cod',
            'payment_plan'     => 'on_delivery',
        ])->assertCreated();

        $item = Order::latest('id')->firstOrFail()->items()->firstOrFail();

        $this->assertSame('37.00', $item->weight_kg);
        $this->assertSame('43166.67', $item->unit_price);
        // The rate is kept too, so the line still adds up after the seller
        // changes what they charge.
        $this->assertSame('1166.67', $item->price_per_kg);

        $this->goat->update(['price' => 30000]);
        $this->assertSame('43166.67', $item->fresh()->unit_price);
    }

    public function test_a_seller_only_adds_a_ceiling_and_the_rate_follows(): void
    {
        $user = User::create([
            'name' => 'Kilo Farm', 'email' => 'kilofarm@example.test',
            'phone' => '+977 9800-222222', 'password' => 'password',
            'role' => 'customer', 'is_active' => true,
        ]);

        $seller = Seller::create([
            'user_id'       => $user->id,
            'farm_name'     => 'Kilo Farm',
            'contact_phone' => '+977 9800-222222',
            'city'          => 'Kathmandu',
            'status'        => 'approved',
            'approved_at'   => now(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/listings', [
            'category_id'    => Category::first()->id,
            'name'           => 'Jamunapari Buck',
            'gender'         => 'male',
            'weight_kg'      => 20,
            'price'          => 24000,
            'max_weight_kg'  => 45,
            'weight_step_kg' => 1,
            'stock'          => 3,
        ])->assertCreated();

        $listing = Goat::where('seller_id', $seller->id)->firstOrFail();

        // The seller filled in a price and a weight, as they always have. The
        // only new field is the ceiling; the rate came out of the other two.
        $this->assertTrue($listing->is_weight_priced);
        $this->assertSame('1200.00', $listing->price_per_kg);
        $this->assertSame(54000.0, $listing->priceForWeight(45));
    }

    public function test_a_ceiling_below_the_advertised_weight_is_refused(): void
    {
        $user = User::create([
            'name' => 'Vague Farm', 'email' => 'vaguefarm@example.test',
            'phone' => '+977 9800-333333', 'password' => 'password',
            'role' => 'customer', 'is_active' => true,
        ]);

        Seller::create([
            'user_id'       => $user->id,
            'farm_name'     => 'Vague Farm',
            'contact_phone' => '+977 9800-333333',
            'city'          => 'Kathmandu',
            'status'        => 'approved',
            'approved_at'   => now(),
        ]);

        Sanctum::actingAs($user);

        // A listing cannot cap itself below the weight it is advertising.
        $this->postJson('/api/v1/seller/listings', [
            'category_id'   => Category::first()->id,
            'name'          => 'Backwards Buck',
            'gender'        => 'male',
            'weight_kg'     => 30,
            'price'         => 30000,
            'max_weight_kg' => 20,
            'stock'         => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('max_weight_kg');
    }

    public function test_a_seller_can_offer_lighter_animals_than_the_one_listed(): void
    {
        // Advertised at 18 kg, but they will go down to 12 and up to 40.
        $this->goat->update(['min_weight_kg' => 12]);
        $this->goat->refresh();

        $this->assertSame(12.0, $this->goat->lightest_weight);
        $this->assertSame(40.0, $this->goat->heaviest_weight);

        // Priced off the advertised weight in both directions.
        $this->assertSame(14000.0, $this->goat->priceForWeight(12));
        $this->assertSame(21000.0, $this->goat->priceForWeight(18));
    }

    public function test_the_steps_are_counted_out_from_the_advertised_weight(): void
    {
        // 2 kg steps from a 12 kg floor would run 12, 14 ... 18 is even, so
        // pick a floor that puts the advertised weight off an even grid.
        $this->goat->update(['min_weight_kg' => 11, 'weight_step_kg' => 2]);
        $this->goat->refresh();

        // Counted outward from 18, so 18 is always a stop the buyer can pick.
        $this->assertTrue($this->goat->isWeightAllowed(18.0));
        $this->assertTrue($this->goat->isWeightAllowed(12.0));
        $this->assertFalse($this->goat->isWeightAllowed(11.0));

        // The floor snaps up to the first real stop rather than sitting one
        // kilo below everything the selector can reach.
        $this->assertSame(12.0, $this->goat->lightest_weight);

        // The ceiling is already on the grid here -- 40 is 11 steps of 2 above
        // 18 -- so it stands. The snapping-down case is covered by the 5 kg
        // step test below, where 40 is not reachable.
        $this->assertSame(40.0, $this->goat->heaviest_weight);
    }

    public function test_changing_the_step_changes_what_the_buyer_can_pick(): void
    {
        $this->goat->update(['weight_step_kg' => 5]);
        $this->goat->refresh();

        $this->assertTrue($this->goat->isWeightAllowed(23.0));
        $this->assertFalse($this->goat->isWeightAllowed(25.0));

        // 18, 23, 28, 33, 38 -- 40 is not reachable in fives from 18.
        $this->assertCount(5, $this->goat->weightOptions());
        $this->assertSame(38.0, $this->goat->heaviest_weight);
    }

    public function test_every_view_of_a_placed_line_carries_its_weight(): void
    {
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 30])
            ->assertCreated()
            // The cart line the checkout summary reads.
            ->assertJsonPath('data.items.0.weight_kg', 30);

        $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Raj Ali',
            'customer_email'   => 'rahim@example.test',
            'area'             => 'Ward 4',
            'postal_code'      => '44600',
            'customer_phone'   => '+977 9800-111111',
            'address_line'     => 'House 12',
            'city'             => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method'   => 'cod',
            'payment_plan'     => 'on_delivery',
        ])->assertCreated();

        $number = Order::latest('id')->firstOrFail()->order_number;

        // The buyer's own order page.
        $this->getJson('/api/v1/orders/'.$number)
            ->assertOk()
            ->assertJsonPath('data.items.0.weight_kg', 30);
    }

    public function test_the_seller_is_told_which_weight_to_prepare(): void
    {
        $user = User::create([
            'name' => 'Weighed Farm', 'email' => 'weighed@example.test',
            'phone' => '+977 9800-444444', 'password' => 'password',
            'role' => 'customer', 'is_active' => true,
        ]);

        $seller = Seller::create([
            'user_id'       => $user->id,
            'farm_name'     => 'Weighed Farm',
            'contact_phone' => '+977 9800-444444',
            'city'          => 'Kathmandu',
            'status'        => 'approved',
            'approved_at'   => now(),
        ]);

        $this->goat->update(['seller_id' => $seller->id]);

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => 30])->assertCreated();
        $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Raj Ali',
            'customer_email'   => 'rahim@example.test',
            'area'             => 'Ward 4',
            'postal_code'      => '44600',
            'customer_phone'   => '+977 9800-111111',
            'address_line'     => 'House 12',
            'city'             => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method'   => 'cod',
            'payment_plan'     => 'on_delivery',
        ])->assertCreated();

        Sanctum::actingAs($user);

        // Without this the seller only has the listing name to go on, and a
        // listing sold across a range cannot say which animal to bring.
        $this->getJson('/api/v1/seller/orders')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.weight_kg', 30);
    }

    public function test_a_weight_in_the_name_is_taken_out_on_save(): void
    {
        $goat = Goat::create([
            'category_id'     => Category::first()->id,
            'name'            => 'Sirohi Doe — 33kg',
            'gender'          => 'female',
            'weight_kg'       => 33,
            'price'           => 33000,
            'status'          => 'published',
            'approval_status' => 'approved',
        ]);

        // The weight is a field of its own. Repeating it in the name is what
        // made a 30 kg order read "Totapuri Buck — 41kg" on the buyer's
        // summary and on the seller's picking list.
        $this->assertSame('Sirohi Doe', $goat->fresh()->name);

        // And the slug is built from the cleaned name, so the URL is clean too.
        $this->assertStringStartsWith('sirohi-doe-', $goat->fresh()->slug);
        $this->assertStringNotContainsString('33kg', $goat->fresh()->slug);
    }

    public function test_only_a_trailing_weight_is_removed_from_a_name(): void
    {
        // Every dash the shop might use, and none at all.
        $this->assertSame('Barbari Buck', Goat::withoutTrailingWeight('Barbari Buck - 18 kg'));
        $this->assertSame('Boer Cross Buck', Goat::withoutTrailingWeight('Boer Cross Buck – 52 Kg'));
        $this->assertSame('Khari Khasi', Goat::withoutTrailingWeight('Khari Khasi 28kg'));
        $this->assertSame('Jamunapari Doe', Goat::withoutTrailingWeight('Jamunapari Doe — 40.5kg'));

        // A name with no weight in it is left exactly as it was.
        $this->assertSame('Plain Buck', Goat::withoutTrailingWeight('Plain Buck'));

        // A weight in the middle is part of the name, not a suffix.
        $this->assertSame('Goat 2kg Special', Goat::withoutTrailingWeight('Goat 2kg Special'));

        // And a name that is nothing but a weight is kept rather than emptied.
        $this->assertSame('22kg', Goat::withoutTrailingWeight('22kg'));
    }

    public function test_a_seller_listing_comes_out_of_the_api_clean(): void
    {
        $user = User::create([
            'name' => 'Naming Farm', 'email' => 'naming@example.test',
            'phone' => '+977 9800-555555', 'password' => 'password',
            'role' => 'customer', 'is_active' => true,
        ]);

        $seller = Seller::create([
            'user_id'       => $user->id,
            'farm_name'     => 'Naming Farm',
            'contact_phone' => '+977 9800-555555',
            'city'          => 'Kathmandu',
            'status'        => 'approved',
            'approved_at'   => now(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/listings', [
            'category_id' => Category::first()->id,
            'name'        => 'Khari Khasi — 28kg',
            'gender'      => 'male',
            'weight_kg'   => 28,
            'price'       => 28000,
            'stock'       => 1,
        ])->assertCreated();

        $this->assertSame('Khari Khasi', Goat::where('seller_id', $seller->id)->firstOrFail()->name);
    }

    /** Places a paid, out-for-delivery order for one goat at the given weight. */
    private function orderAtWeight(float $kg): Order
    {
        Sanctum::actingAs($this->buyer);

        $this->deleteJson('/api/v1/cart');
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id, 'weight_kg' => $kg])
            ->assertCreated();

        $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Raj Ali',
            'customer_email'   => 'rahim@example.test',
            'area'             => 'Ward 4',
            'postal_code'      => '44600',
            'customer_phone'   => '+977 9800-111111',
            'address_line'     => 'House 12',
            'city'             => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method'   => 'cod',
            'payment_plan'     => 'on_delivery',
        ])->assertCreated();

        $order = Order::latest('id')->firstOrFail();

        // Straight to the door: the sequence itself is tested elsewhere.
        $order->update(['status' => 'out_for_delivery']);
        $order->update(['paid_amount' => $order->total, 'payment_status' => 'paid']);

        return $order->fresh();
    }

    public function test_a_lighter_goat_at_the_door_is_recorded_against_the_order(): void
    {
        $order = $this->orderAtWeight(25);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        $item = $order->items()->firstOrFail();

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [
                    ['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 23],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $item->refresh();

        // Ordered and delivered are kept apart: overwriting the first would
        // erase what the buyer actually agreed to pay for.
        $this->assertSame('25.00', $item->weight_kg);
        $this->assertSame('23.00', $item->delivered_weight_kg);
        $this->assertSame(-2.0, $item->weight_delta);
        $this->assertSame('decreased', $item->weight_direction);
        $this->assertNotNull($item->weighed_at);
        $this->assertNotNull($item->weighed_by);
    }

    public function test_a_heavier_goat_is_recorded_the_same_way(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'increased',
                'weights'          => [
                    ['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 27],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2.0, $item->refresh()->weight_delta);
        $this->assertSame('increased', $item->weight_direction);
    }

    public function test_the_direction_and_the_figure_have_to_agree(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        // Says decreased, types a heavier figure. Letting this through would
        // leave a record whose words and numbers disagree.
        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [
                    ['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 27],
                ],
            ])
            ->assertHasTableActionErrors();

        $this->assertNull($item->refresh()->delivered_weight_kg);
        $this->assertSame(0.0, (float) $order->fresh()->weight_adjustment);
    }

    public function test_answering_yes_records_the_ordered_weight_as_checked(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: ['weight_same' => 1])
            ->assertHasNoTableActionErrors();

        // "Yes" is a reading too: someone checked and it matched, which is a
        // different record from never having weighed it at all.
        $this->assertSame('25.00', $item->refresh()->delivered_weight_kg);
        $this->assertSame('same', $item->weight_direction);

        // Nothing moved, so nothing comes off the bill.
        $this->assertSame(0.0, (float) $order->fresh()->weight_adjustment);
    }

    public function test_the_buyer_is_told_what_the_scale_said(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [
                    ['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 23],
                ],
            ])
            ->assertHasNoTableActionErrors();

        Sanctum::actingAs($this->buyer);

        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.items.0.weight_kg', 25)
            ->assertJsonPath('data.items.0.delivered_weight_kg', 23)
            ->assertJsonPath('data.items.0.weight_direction', 'decreased');
    }

    public function test_a_lighter_goat_takes_money_off_the_bill(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        // 25 kg of a listing advertised at 18 kg for 21,000.
        $orderedLine = (float) $item->line_total;
        $subtotal    = (float) $order->subtotal;

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 23]],
            ])
            ->assertHasNoTableActionErrors();

        $item->refresh();
        $order->refresh();

        // Scaled off the agreed line, not off the rounded rate.
        $expected = round($orderedLine * 23 / 25, 2);

        $this->assertSame($expected, $item->charged_line_total);
        $this->assertSame(round($expected - $orderedLine, 2), (float) $item->price_adjustment);

        // What was agreed survives untouched beside what was charged.
        $this->assertSame($orderedLine, (float) $item->line_total);
        $this->assertSame($subtotal, (float) $order->subtotal);

        // The order total moves by exactly the line's difference; the coupon
        // and delivery charge agreed at checkout are left alone.
        $this->assertSame(round($expected - $orderedLine, 2), (float) $order->weight_adjustment);
        $this->assertSame(
            round($subtotal + (float) $order->weight_adjustment
                - (float) $order->discount + (float) $order->delivery_charge, 2),
            (float) $order->total
        );
    }

    public function test_a_heavier_goat_adds_to_the_bill(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();
        $orderedLine = (float) $item->line_total;

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'increased',
                'weights'          => [['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 27]],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertGreaterThan(0, (float) $order->fresh()->weight_adjustment);
        $this->assertSame(round($orderedLine * 27 / 25, 2), $item->refresh()->charged_line_total);
    }

    public function test_the_seller_is_paid_on_what_was_charged_not_what_was_ordered(): void
    {
        $seller = Seller::where('slug', 'karim-livestock')->firstOrFail();
        $this->goat->update(['seller_id' => $seller->id]);

        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 23]],
            ])
            ->assertHasNoTableActionErrors();

        $item->refresh();

        // Payouts settle on delivery, so commission and earnings have to be
        // right before then -- otherwise money is clawed back from a seller.
        $charged    = $item->charged_line_total;
        $commission = round($charged * ((float) $item->commission_rate / 100), 2);

        $this->assertSame($commission, (float) $item->commission_amount);
        $this->assertSame(round($charged - $commission, 2), (float) $item->seller_earning);
    }

    public function test_the_buyer_sees_the_money_the_weight_moved(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 23]],
            ])
            ->assertHasNoTableActionErrors();

        Sanctum::actingAs($this->buyer);

        $body = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data');

        // Compared by value: JSON hands back a whole number as an int.
        $this->assertEquals(23, $body['items'][0]['delivered_weight_kg']);
        $this->assertLessThan(0, $body['items'][0]['price_adjustment']);
        $this->assertLessThan(0, $body['totals']['weight_adjustment']);
    }

    public function test_a_lighter_goat_leaves_a_prepaid_buyer_owed_money(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        // Paid in full at the ordered weight.
        $order->update(['paid_amount' => $order->total, 'payment_status' => 'paid']);
        $paid = (float) $order->fresh()->paid_amount;

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 23]],
            ])
            ->assertHasNoTableActionErrors();

        $order->refresh();

        // The order is live and going ahead -- it simply costs less than the
        // buyer already handed over.
        $owed = round($paid - (float) $order->total, 2);

        $this->assertGreaterThan(0, $owed);
        $this->assertSame($owed, $order->overpaid_amount);
        $this->assertSame($owed, $order->refundable_amount);
        $this->assertTrue($order->isRefundable());
    }

    public function test_the_buyer_can_ask_for_the_overpayment_back(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();
        $order->update(['paid_amount' => $order->total, 'payment_status' => 'paid']);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 23]],
            ])
            ->assertHasNoTableActionErrors();

        $owed = $order->fresh()->overpaid_amount;

        // Money only goes back out on a rail that can carry it.
        PaymentMethod::where('code', 'esewa')->update([
            'is_active' => true, 'supports_payout' => true,
        ]);

        Sanctum::actingAs($this->buyer);

        // The buyer's own order page offers it without the order being
        // cancelled, and says why rather than claiming it was called off.
        $refund = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->json('data.refund');

        $this->assertEquals($owed, $refund['amount']);
        $this->assertSame('overpaid', $refund['reason']);
        $this->assertTrue($refund['can_request']);

        $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method'            => 'esewa',
            'refund_to_name'    => 'Raj Ali',
            'refund_to_account' => '9800000000',
        ])->assertCreated();

        $refundRow = $order->payments()->where('type', 'refund')->firstOrFail();

        $this->assertSame('pending', $refundRow->status);
        $this->assertEquals($owed, (float) $refundRow->amount);
    }

    public function test_sending_the_refund_does_not_deliver_the_order(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();
        $order->update(['paid_amount' => $order->total, 'payment_status' => 'paid']);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 23]],
            ])
            ->assertHasNoTableActionErrors();

        PaymentMethod::where('code', 'esewa')->update([
            'is_active' => true, 'supports_payout' => true,
        ]);

        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method'            => 'esewa',
            'refund_to_name'    => 'Raj Ali',
            'refund_to_account' => '9800000000',
        ])->assertCreated();

        $refund = $order->payments()->where('type', 'refund')->firstOrFail();

        // Staff send the money back. Confirming it drops `paid` to meet the
        // total, which used to read as "settled" and closed the order -- on the
        // strength of a payment travelling the other way.
        app(PaymentService::class)->confirm($refund, User::where('role', 'admin')->firstOrFail());

        $order->refresh();

        $this->assertSame('out_for_delivery', $order->status);
        $this->assertNull($order->delivered_at);
    }

    public function test_an_order_that_owes_nothing_is_not_refundable(): void
    {
        $order = $this->orderAtWeight(25);
        $order->update(['paid_amount' => $order->total, 'payment_status' => 'paid']);

        // Paid exactly, nothing weighed light.
        $this->assertSame(0.0, $order->fresh()->overpaid_amount);
        $this->assertFalse($order->fresh()->isRefundable());
    }

    public function test_the_admin_form_shows_the_rate_it_worked_out(): void
    {
        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        // Mounted for real: the rate is a computed placeholder rather than a
        // stored field, so nothing else would catch it throwing.
        Livewire::test(EditGoat::class, ['record' => $this->goat->getRouteKey()])
            ->assertFormFieldExists('min_weight_kg')
            ->assertFormFieldExists('max_weight_kg')
            ->assertFormFieldExists('weight_step_kg')
            ->assertSee('1,166.67')
            // The rate is worked out, never typed, so there is no box for it.
            ->assertFormFieldDoesNotExist('price_per_kg')
            ->assertOk();
    }

    public function test_a_listing_with_no_ceiling_is_untouched_by_any_of_this(): void
    {
        Sanctum::actingAs($this->buyer);

        $fixed = Goat::create([
            'category_id'     => Category::first()->id,
            'name'            => 'One Of A Kind',
            'gender'          => 'male',
            'weight_kg'       => 22,
            'price'           => 21000,
            'stock'           => 1,
            'track_stock'     => true,
            'status'          => 'published',
            'approval_status' => 'approved',
        ]);

        $this->assertFalse($fixed->is_weight_priced);
        $this->assertSame(21000.0, $fixed->effective_price);

        $cart = $this->postJson('/api/v1/cart', ['goat_id' => $fixed->id])
            ->assertCreated()
            ->json('data');

        $this->assertNull($cart['items'][0]['weight_kg']);
        $this->assertEquals(21000, $cart['items'][0]['unit_price']);
    }

    /**
     * The admin totals card has to reconcile on its own.
     *
     * Staff read this card to answer "why is this order NPR 26,666.67 when the
     * goats came to 29,166.67?" -- and before this it could not be answered
     * from the page at all: the adjustment moved the total and was printed
     * nowhere. Guards every future re-weighed order, not just the one that
     * showed the problem.
     */
    public function test_the_admin_totals_card_shows_the_money_the_scale_moved(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 22]],
            ])
            ->assertHasNoTableActionErrors();

        $order->refresh();

        $this->assertTrue($order->hasWeightAdjustment());

        // The four figures alone contradict the total -- which is exactly what
        // staff were being shown.
        $this->assertNotSame(
            round((float) $order->subtotal - (float) $order->discount
                + (float) $order->delivery_charge, 2),
            (float) $order->total
        );

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertOk()
            ->assertSee('Weight adjustment')
            ->assertSee(number_format(abs((float) $order->weight_adjustment), 2));
    }

    public function test_an_order_that_was_never_re_weighed_keeps_the_plain_totals_card(): void
    {
        $order = $this->orderAtWeight(25);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        $this->assertFalse($order->hasWeightAdjustment());

        // Nothing to explain, so nothing extra is printed: the row only earns
        // its place when the subtotal and the total disagree.
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertOk()
            ->assertDontSee('Weight adjustment');
    }

    public function test_the_order_edit_screen_shows_the_adjustment_too(): void
    {
        $order = $this->orderAtWeight(25);
        $item  = $order->items()->firstOrFail();

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('recordWeight', $order, data: [
                'weight_same'      => 0,
                'weight_direction' => 'decreased',
                'weights'          => [['item_id' => $item->id, 'ordered' => 25.0, 'kg' => 22]],
            ])
            ->assertHasNoTableActionErrors();

        // The Summary card on the edit screen lists the same four figures, so
        // it had the same hole in it.
        Livewire::test(EditOrder::class, ['record' => $order->fresh()->getRouteKey()])
            ->assertOk()
            ->assertSee('Weight adjustment');
    }
}
