<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use App\Services\PayoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryEarningTest extends TestCase
{
    use RefreshDatabase;

    private Seller $seller;
    private User $buyer;
    private DeliveryZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->seller = Seller::where('slug', 'karim-livestock')->firstOrFail();
        $this->buyer = User::where('role', 'customer')
            ->where('id', '!=', $this->seller->user_id)->firstOrFail();

        // A zone with a charge that is not waived by the free-delivery threshold.
        $this->zone = DeliveryZone::active()->orderByDesc('charge')->firstOrFail();
        $this->zone->update(['free_above' => null]);
    }

    private function goat(?Seller $owner, string $name, float $price = 25000): Goat
    {
        return Goat::create([
            'category_id' => Category::first()->id,
            'seller_id'   => $owner?->id,
            'name' => $name, 'gender' => 'male', 'price' => $price,
            'stock' => 1, 'track_stock' => true,
            'status' => 'published', 'approval_status' => 'approved',
        ]);
    }

    private function order(array $ids): Order
    {
        Sanctum::actingAs($this->buyer);
        $this->deleteJson('/api/v1/cart');

        foreach ($ids as $id) {
            $this->postJson('/api/v1/cart', ['goat_id' => $id])->assertCreated();
        }

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim', 'customer_phone' => '+977 9801-111111',
            'address_line' => 'Baghbazar', 'city' => 'Kathmandu',
            'delivery_zone_id' => $this->zone->id,
            'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    public function test_a_seller_run_order_credits_the_delivery_charge_to_the_seller(): void
    {
        $order = $this->order([$this->goat($this->seller, 'Delivery Test Goat')->id]);

        $this->assertSame($this->seller->id, $order->delivery_seller_id);
        $this->assertEquals($order->delivery_charge, $order->delivery_earning);
        $this->assertGreaterThan(0, (float) $order->delivery_earning);
    }

    public function test_no_commission_is_taken_on_the_delivery_charge(): void
    {
        $order = $this->order([$this->goat($this->seller, 'No Commission Goat')->id]);
        $line = $order->items()->firstOrFail();

        // Commission is 10% of the goat only, never of delivery.
        $this->assertEquals(2500, $line->commission_amount);
        $this->assertEquals(22500, $line->seller_earning);
        $this->assertEquals($order->delivery_charge, $order->delivery_earning);
    }

    public function test_a_mixed_order_leaves_delivery_with_the_platform(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $order = $this->order([$this->goat($this->seller, 'Mixed Goat')->id, $house->id]);

        $this->assertNull($order->delivery_seller_id);
        $this->assertEquals(0, $order->delivery_earning);
    }

    public function test_delivery_flows_into_earnings_only_after_delivery(): void
    {
        $order = $this->order([$this->goat($this->seller, 'Flow Goat')->id]);
        $delivery = (float) $order->delivery_charge;

        $seller = $this->seller->fresh();
        $this->assertEquals(22500 + $delivery, $seller->pending_earnings);
        $this->assertEquals(0, $seller->unpaid_earnings);

        $this->markDelivered($order);
        $seller = $seller->fresh();

        $this->assertEquals(22500 + $delivery, $seller->unpaid_earnings);
        $this->assertEquals(22500 + $delivery, $seller->lifetime_earnings);
        $this->assertEquals(0, $seller->pending_earnings);
    }

    public function test_a_payout_settles_the_delivery_charge_too(): void
    {
        $order = $this->order([$this->goat($this->seller, 'Payout Goat')->id]);
        $delivery = (float) $order->delivery_charge;

        $this->markDelivered($order);

        $payout = app(PayoutService::class)->settle($this->seller->fresh());

        $this->assertEquals(22500 + $delivery, $payout->amount);
        $this->assertSame($payout->id, $order->fresh()->delivery_payout_id);
        $this->assertEquals(0, $this->seller->fresh()->unpaid_earnings);
    }

    public function test_a_failed_payout_releases_the_delivery_charge_as_well(): void
    {
        $order = $this->order([$this->goat($this->seller, 'Failed Payout Goat')->id]);
        $delivery = (float) $order->delivery_charge;

        $this->markDelivered($order);

        $service = app(PayoutService::class);
        $payout = $service->settle($this->seller->fresh());
        $service->markFailed($payout, 'Bank rejected it');

        $this->assertNull($order->fresh()->delivery_payout_id);
        $this->assertEquals(22500 + $delivery, $this->seller->fresh()->unpaid_earnings);
    }

    public function test_the_seller_order_list_shows_the_delivery_breakdown(): void
    {
        $order = $this->order([$this->goat($this->seller, 'Breakdown Goat')->id]);
        $delivery = (float) $order->delivery_charge;

        Sanctum::actingAs($this->seller->user);

        $totals = $this->getJson('/api/v1/seller/orders')
            ->assertOk()
            ->assertJsonPath('data.0.totals.delivery_is_yours', true)
            ->json('data.0.totals');

        // JSON renders 3500.0 as 3500, so money comparisons are loose.
        $this->assertEquals($delivery, $totals['delivery_charge']);
        $this->assertEquals($delivery, $totals['delivery_earning']);
        $this->assertEquals(22500 + $delivery, $totals['earning']);
        $this->assertEquals((float) $order->total, $totals['buyer_paid']);
    }
}
