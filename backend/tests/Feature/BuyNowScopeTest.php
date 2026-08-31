<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Buy now" means this goat, not the cart.
 *
 * A buyer with three animals already in their cart who clicked Buy now on a
 * fourth was ordering all four — the checkout simply took whatever was in the
 * cart, with no notion of what had been chosen.
 */
class BuyNowScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->buyer = User::where('role', 'customer')->firstOrFail();

        PaymentMethod::where('code', 'esewa')->update([
            'is_active' => true,
            'payee_account_name' => 'Goat Haven Pvt Ltd',
            'payee_account_number' => '9800000000',
        ]);
    }

    private function goat(string $name, float $price): Goat
    {
        return Goat::create([
            'category_id' => Category::first()->id,
            'name' => $name,
            'gender' => 'male',
            'price' => $price,
            'stock' => 3,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
    }

    private function checkout(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/checkout', array_merge([
            'customer_name' => 'Rahim Uddin',
            'customer_email' => 'rahim@example.test',
            'area' => 'Ward 4',
            'postal_code' => '44600',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'esewa',
            'payment_plan' => 'full',
        ], $body));
    }

    public function test_buying_one_goat_leaves_the_rest_of_the_cart_alone(): void
    {
        $wanted = $this->goat('Boer Cross Buck', 95000);
        $other1 = $this->goat('Khari Khasi 28kg', 31500);
        $other2 = $this->goat('Khari Khasi 34kg', 42000);

        Sanctum::actingAs($this->buyer);

        foreach ([$other1, $other2, $wanted] as $goat) {
            $this->postJson('/api/v1/cart', ['goat_id' => $goat->id])->assertCreated();
        }

        $number = $this->checkout(['goat_ids' => [$wanted->id]])
            ->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->firstOrFail();

        // Only the chosen animal was bought...
        $this->assertCount(1, $order->items);
        $this->assertSame('Boer Cross Buck', $order->items->first()->goat_name);
        $this->assertEquals(95000, $order->subtotal);

        // ...and the other two are still waiting in the cart.
        $remaining = $this->getJson('/api/v1/cart')->assertOk()->json('data.items');

        $this->assertCount(2, $remaining);
        $this->assertEqualsCanonicalizing(
            [$other1->id, $other2->id],
            collect($remaining)->pluck('goat.id')->all()
        );
    }

    /** The ordinary path is untouched: no selection means the whole cart. */
    public function test_checking_out_without_a_selection_still_takes_everything(): void
    {
        $first = $this->goat('First Goat', 20000);
        $second = $this->goat('Second Goat', 30000);

        Sanctum::actingAs($this->buyer);

        foreach ([$first, $second] as $goat) {
            $this->postJson('/api/v1/cart', ['goat_id' => $goat->id])->assertCreated();
        }

        $number = $this->checkout()->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        $this->assertCount(2, $order->items);
        $this->assertEquals(50000, $order->subtotal);
        $this->assertSame([], $this->getJson('/api/v1/cart')->json('data.items'));
    }

    public function test_the_total_is_the_chosen_goat_not_the_basket(): void
    {
        $cheap = $this->goat('Cheap Goat', 10000);
        $dear = $this->goat('Dear Goat', 90000);

        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $dear->id])->assertCreated();
        $this->postJson('/api/v1/cart', ['goat_id' => $cheap->id])->assertCreated();

        $number = $this->checkout(['goat_ids' => [$cheap->id]])
            ->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->firstOrFail();

        // Delivery is worked out on the chosen goat alone, so a cheap animal
        // does not get free delivery off the back of what is sitting behind it.
        $this->assertEquals(10000, $order->subtotal);
        $this->assertEquals(10000 + $order->delivery_charge, $order->total);
    }

    public function test_asking_for_a_goat_that_is_not_in_the_cart_is_refused(): void
    {
        $inCart = $this->goat('In Cart', 20000);
        $elsewhere = $this->goat('Not In Cart', 30000);

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/v1/cart', ['goat_id' => $inCart->id])->assertCreated();

        $this->checkout(['goat_ids' => [$elsewhere->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('goat_ids');

        $this->assertSame(0, Order::count());
        $this->assertCount(1, $this->getJson('/api/v1/cart')->json('data.items'));
    }
}
