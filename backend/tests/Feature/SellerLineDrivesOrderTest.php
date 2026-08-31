<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerLineDrivesOrderTest extends TestCase
{
    use RefreshDatabase;

    private Seller $seller;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->seller = Seller::where('slug', 'karim-livestock')->firstOrFail();
        $this->buyer = User::where('role', 'customer')
            ->where('id', '!=', $this->seller->user_id)->firstOrFail();
    }

    private function sellerOnlyOrder(): Order
    {
        $goat = Goat::create([
            'category_id' => Category::first()->id, 'seller_id' => $this->seller->id,
            'name' => 'Line Drives Order Goat', 'gender' => 'male', 'price' => 25000,
            'stock' => 1, 'track_stock' => true,
            'status' => 'published', 'approval_status' => 'approved',
        ]);

        Sanctum::actingAs($this->buyer);
        $this->deleteJson('/api/v1/cart');
        $this->postJson('/api/v1/cart', ['goat_id' => $goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim', 'customer_phone' => '+977 9801-111111',
            'customer_email' => 'rahim@example.test',
            'area' => 'Ward 4',
            'postal_code' => '44600',
            'address_line' => 'Baghbazar', 'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    /**
     * The reported bug: on a seller-run order the seller advanced their line to
     * "handed over" and the order sat on pending, so the buyer's timeline never
     * moved past "Placed".
     */
    public function test_advancing_a_line_moves_a_seller_run_order(): void
    {
        $order = $this->sellerOnlyOrder();
        $line = $order->items()->firstOrFail();

        Sanctum::actingAs($this->seller->user);

        $this->putJson("/api/v1/seller/order-items/{$line->id}/status", ['status' => 'handed_over'])
            ->assertOk();

        $this->assertSame('handed_over', $line->fresh()->fulfilment_status);
        $this->assertSame(
            'out_for_delivery',
            $order->fresh()->status,
            'A seller-run order must follow its own line, not sit on pending'
        );

        // And the buyer sees it.
        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/v1/orders/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('data.status', 'out_for_delivery');
    }

    public function test_a_part_way_line_moves_the_order_part_way(): void
    {
        $order = $this->sellerOnlyOrder();
        $line = $order->items()->firstOrFail();

        Sanctum::actingAs($this->seller->user);
        $this->putJson("/api/v1/seller/order-items/{$line->id}/status", ['status' => 'preparing'])->assertOk();

        $this->assertSame('processing', $order->fresh()->status);
    }

    /** Money must not look earned before the goat is delivered. */
    public function test_earnings_are_not_counted_before_delivery(): void
    {
        $order = $this->sellerOnlyOrder();
        $seller = $this->seller->fresh();

        // Single-seller order, so the delivery charge is theirs too.
        $expected = 22500 + (float) $order->delivery_earning;

        $this->assertEquals(0, $seller->unpaid_earnings, 'Nothing is payable before delivery');
        $this->assertEquals(0, $seller->lifetime_earnings, 'Nothing is earned before delivery');
        $this->assertEquals($expected, $seller->pending_earnings, 'It should show as still in flight');

        $this->markDelivered($order);
        $seller = $seller->fresh();

        $this->assertEquals($expected, $seller->unpaid_earnings);
        $this->assertEquals($expected, $seller->lifetime_earnings);
        $this->assertEquals(0, $seller->pending_earnings);
    }

    public function test_the_dashboard_separates_in_flight_money_from_earned(): void
    {
        $order = $this->sellerOnlyOrder();
        $expected = 22500 + (float) $order->delivery_earning;

        Sanctum::actingAs($this->seller->user);

        $earnings = $this->getJson('/api/v1/seller/dashboard')->assertOk()->json('data.earnings');

        $this->assertEquals($expected, $earnings['pending']);
        $this->assertEquals(0, $earnings['unpaid']);
        $this->assertEquals(0, $earnings['lifetime']);
    }
}
