<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payout;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\SellerSaleNotification;
use App\Services\PayoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketplaceMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Seller $seller;
    private Goat $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $sellerUser = User::create([
            'name' => 'Karim Farms', 'email' => 'karim@example.test', 'phone' => '+880 1700-222222',
            'password' => 'password', 'role' => 'customer', 'is_active' => true,
        ]);

        $this->seller = Seller::create([
            'user_id' => $sellerUser->id,
            'farm_name' => 'Karim Livestock',
            'contact_phone' => '+880 1700-222222',
            'city' => 'Dhaka',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->goat = Goat::create([
            'category_id' => Category::first()->id,
            'seller_id' => $this->seller->id,
            'name' => 'Karim Black Bengal — 20kg',
            'gender' => 'male',
            'price' => 30000,
            'stock' => 1,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
    }

    /** Delivery on a single-seller order is earned by that seller. */
    private function deliveryEarning(Order $order): float
    {
        return (float) $order->delivery_earning;
    }

    private function buy(): Order
    {
        $buyer = User::where('role', 'customer')->where('id', '!=', $this->seller->user_id)->firstOrFail();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+880 1811-111111',
            'address_line' => 'House 12',
            'city' => 'Dhaka',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    public function test_commission_is_snapshotted_onto_the_order_line(): void
    {
        Notification::fake();

        $order = $this->buy();
        $item = $order->items()->firstOrFail();

        // Default commission is 10%.
        $this->assertEquals($this->seller->id, $item->seller_id);
        $this->assertSame('Karim Livestock', $item->seller_name);
        $this->assertEquals(10.00, $item->commission_rate);
        $this->assertEquals(3000, $item->commission_amount);
        $this->assertEquals(27000, $item->seller_earning);
    }

    public function test_house_stock_carries_no_commission(): void
    {
        Notification::fake();

        $houseGoat = Goat::published()->whereNull('seller_id')->firstOrFail();

        $buyer = User::where('role', 'customer')->where('id', '!=', $this->seller->user_id)->firstOrFail();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $houseGoat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin', 'customer_phone' => '+880 1811-111111',
            'address_line' => 'House 12', 'city' => 'Dhaka',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        $item = Order::where('order_number', $number)->firstOrFail()->items()->firstOrFail();

        $this->assertNull($item->seller_id);
        $this->assertEquals(0, $item->commission_amount);
        $this->assertEquals(0, $item->seller_earning);
    }

    public function test_a_seller_specific_rate_overrides_the_default(): void
    {
        Notification::fake();

        $this->seller->update(['commission_rate' => 5]);

        $item = $this->buy()->items()->firstOrFail();

        $this->assertEquals(5.00, $item->commission_rate);
        $this->assertEquals(1500, $item->commission_amount);
        $this->assertEquals(28500, $item->seller_earning);
    }

    public function test_the_seller_is_told_about_the_sale(): void
    {
        Notification::fake();

        $this->buy();

        Notification::assertSentTo(
            $this->seller->user,
            SellerSaleNotification::class,
            fn (SellerSaleNotification $n) => $n->items->first()->seller_id === $this->seller->id
        );
    }

    public function test_earnings_only_become_payable_once_the_order_is_delivered(): void
    {
        Notification::fake();

        $order = $this->buy();

        // The seller supplied the whole order, so they also earn the delivery.
        $expected = 27000 + $this->deliveryEarning($order);

        // Sold, but not delivered: nothing is owed and nothing is earned yet —
        // it sits in `pending` so it cannot be mistaken for money in hand.
        $this->assertEquals(0, $this->seller->fresh()->unpaid_earnings);
        $this->assertEquals(0, $this->seller->fresh()->lifetime_earnings);
        $this->assertEquals($expected, $this->seller->fresh()->pending_earnings);

        $order->update(['status' => 'delivered']);

        $this->assertEquals($expected, $this->seller->fresh()->unpaid_earnings);
        $this->assertEquals($expected, $this->seller->fresh()->lifetime_earnings);
        $this->assertEquals(0, $this->seller->fresh()->pending_earnings);
    }

    public function test_a_cancelled_order_earns_the_seller_nothing(): void
    {
        Notification::fake();

        $order = $this->buy();
        $order->update(['status' => 'cancelled']);

        $this->assertEquals(0, $this->seller->fresh()->lifetime_earnings);
        $this->assertEquals(0, $this->seller->fresh()->unpaid_earnings);
    }

    public function test_a_payout_settles_earnings_exactly_once(): void
    {
        Notification::fake();

        $order = $this->buy();
        $order->update(['status' => 'delivered']);

        $payout = app(PayoutService::class)->settle($this->seller->fresh());

        $this->assertEquals(27000 + $this->deliveryEarning($order), $payout->amount);
        $this->assertSame('pending', $payout->status);
        $this->assertSame(1, $payout->items()->count());

        // The line is stamped, so the balance drops to zero.
        $this->assertEquals(0, $this->seller->fresh()->unpaid_earnings);

        // A second attempt has nothing left to settle.
        $this->expectException(ValidationException::class);
        app(PayoutService::class)->settle($this->seller->fresh());
    }

    public function test_a_payout_below_the_minimum_is_refused(): void
    {
        Notification::fake();

        Setting::where('key', 'min_payout_amount')->first()->update(['value' => '50000']);

        $order = $this->buy();
        $order->update(['status' => 'delivered']);

        $this->expectException(ValidationException::class);
        app(PayoutService::class)->settle($this->seller->fresh());
    }

    public function test_a_failed_payout_returns_the_earnings_to_the_queue(): void
    {
        Notification::fake();

        $order = $this->buy();
        $order->update(['status' => 'delivered']);

        $service = app(PayoutService::class);
        $payout = $service->settle($this->seller->fresh());

        $this->assertEquals(0, $this->seller->fresh()->unpaid_earnings);

        $service->markFailed($payout, 'Bank rejected the transfer');

        $this->assertSame('failed', $payout->fresh()->status);
        $this->assertEquals(27000 + $this->deliveryEarning($order), $this->seller->fresh()->unpaid_earnings);
        $this->assertNull(OrderItem::where('seller_id', $this->seller->id)->first()->payout_id);
    }

    public function test_marking_a_payout_paid_records_the_reference(): void
    {
        Notification::fake();

        $order = $this->buy();
        $order->update(['status' => 'delivered']);

        $service = app(PayoutService::class);
        $payout = $service->markPaid($service->settle($this->seller->fresh()), 'BKASH-9911');

        $this->assertSame('paid', $payout->status);
        $this->assertSame('BKASH-9911', $payout->transaction_reference);
        $this->assertNotNull($payout->paid_at);
        $this->assertEquals(
            27000 + $this->deliveryEarning($order),
            (float) $this->seller->fresh()->payouts()->where('status', 'paid')->sum('amount')
        );
    }
}
