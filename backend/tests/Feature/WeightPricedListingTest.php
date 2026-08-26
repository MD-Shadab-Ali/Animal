<?php

namespace Tests\Feature;

use App\Filament\Resources\Goats\Pages\EditGoat;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
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
}
