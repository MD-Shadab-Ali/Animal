<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * How long delivery takes, kept with the order.
 *
 * The zone's estimate was shown while picking a zone and then never again — so
 * the moment it actually mattered, waiting for an animal, it had vanished.
 */
class DeliveryEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private Goat $goat;
    private DeliveryZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->buyer = User::where('role', 'customer')->firstOrFail();
        $this->zone = DeliveryZone::active()->orderBy('sort_order')->firstOrFail();

        $this->zone->update(['estimated_time' => '1-2 days']);

        PaymentMethod::where('code', 'esewa')->update([
            'is_active' => true,
            'payee_account_name' => 'Goat Haven Pvt Ltd',
            'payee_account_number' => '9800000000',
        ]);

        $this->goat = Goat::create([
            'category_id' => Category::first()->id,
            'name' => 'Estimate Goat',
            'gender' => 'male',
            'price' => 30000,
            'stock' => 2,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
    }

    private function placeOrder(): Order
    {
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'delivery_zone_id' => $this->zone->id,
            'payment_method' => 'esewa',
            'payment_plan' => 'full',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    public function test_the_zone_estimate_is_offered_while_choosing(): void
    {
        Sanctum::actingAs($this->buyer);

        $zones = collect($this->getJson('/api/v1/checkout/options')->assertOk()->json('data.delivery_zones'))
            ->keyBy('id');

        $this->assertSame('1-2 days', $zones[$this->zone->id]['estimated_time']);
    }

    public function test_the_order_keeps_the_estimate_it_was_placed_under(): void
    {
        $order = $this->placeOrder();

        $this->assertSame('1-2 days', $order->delivery_estimate);

        $shipping = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.shipping');

        $this->assertSame('1-2 days', $shipping['estimate']);
        $this->assertSame($this->zone->name, $shipping['zone']);
    }

    /** A promise made is a promise kept, whatever the zone says later. */
    public function test_widening_the_zone_later_does_not_rewrite_an_old_order(): void
    {
        $order = $this->placeOrder();

        $this->zone->update(['estimated_time' => '5-7 days']);

        $this->assertSame('1-2 days', $order->fresh()->delivery_estimate);

        $this->assertSame(
            '1-2 days',
            $this->getJson('/api/v1/orders/'.$order->order_number)->json('data.shipping.estimate')
        );

        // A new order takes the new promise.
        $this->goat->update(['stock' => 5]);

        $this->assertSame('5-7 days', $this->placeOrder()->delivery_estimate);
    }

    /** Once it is here, the day it arrived beats any estimate. */
    public function test_a_delivered_order_reports_when_it_actually_arrived(): void
    {
        $order = $this->placeOrder();

        app(PaymentService::class)->record($order, ['amount' => $order->total, 'method' => 'esewa']);
        $order->fresh()->update(['status' => 'out_for_delivery']);

        $data = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data');

        $this->assertSame('out_for_delivery', $data['status']);
        $this->assertNull($data['delivered_at']);

        $order->fresh()->update(['status' => 'delivered']);

        $data = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data');

        $this->assertNotNull($data['delivered_at'], 'The storefront shows this instead of the estimate');
        $this->assertSame('1-2 days', $data['shipping']['estimate']);
    }

    /** A zone with nothing filled in must not invent a promise. */
    public function test_a_zone_with_no_estimate_promises_nothing(): void
    {
        $this->zone->update(['estimated_time' => null]);

        $order = $this->placeOrder();

        $this->assertNull($order->delivery_estimate);
        $this->assertNull(
            $this->getJson('/api/v1/orders/'.$order->order_number)->json('data.shipping.estimate')
        );
    }
}
