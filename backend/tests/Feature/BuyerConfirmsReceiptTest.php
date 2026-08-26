<?php

namespace Tests\Feature;

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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The buyer closing their own order.
 *
 * A prepaid order has no payment left to close it, so it used to sit at "on the
 * way" until staff chased a rider by phone. The buyer is the one person with
 * first-hand knowledge of whether the animal is in their yard.
 */
class BuyerConfirmsReceiptTest extends TestCase
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

        PaymentMethod::where('code', 'esewa')->update([
            'is_active' => true,
            'payee_account_name' => 'Goat Haven Pvt Ltd',
            'payee_account_number' => '9800000000',
        ]);

        $this->goat = Goat::create([
            'category_id' => Category::first()->id,
            'name' => 'Receipt Goat',
            'gender' => 'male',
            'price' => 40000,
            'stock' => 3,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
    }

    private function order(string $plan = 'full'): Order
    {
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'esewa',
            'payment_plan' => $plan,
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    public function test_the_buyer_can_close_a_prepaid_order_by_saying_it_arrived(): void
    {
        $order = $this->order();

        app(PaymentService::class)->record($order, ['amount' => $order->total, 'method' => 'esewa']);
        $order->fresh()->update(['status' => 'out_for_delivery']);

        // The button is offered.
        $this->assertTrue(
            $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()
                ->json('data.can_confirm_receipt')
        );

        $this->postJson('/api/v1/orders/'.$order->order_number.'/received')
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $order = $order->fresh();

        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->delivered_at);

        // Recorded against them, not against staff — it matters in a dispute.
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => 'delivered',
            'user_id' => $this->buyer->id,
            'note' => 'Confirmed received by the customer.',
        ]);

        // And it is no longer sitting in the staff queue.
        $this->assertFalse(
            Order::query()->awaitingDeliveryConfirmation()->whereKey($order->id)->exists()
        );
    }

    /** Confirming is what releases the farm's money. */
    public function test_confirming_receipt_settles_the_seller(): void
    {
        $seller = Seller::create([
            'user_id' => User::create([
                'name' => 'Receipt Farm', 'email' => 'receiptfarm@example.test',
                'phone' => '+977 9800-555555', 'password' => 'password',
                'role' => 'customer', 'is_active' => true,
            ])->id,
            'farm_name' => 'Receipt Farm',
            'contact_phone' => '+977 9800-555555',
            'city' => 'Kathmandu',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->goat->update(['seller_id' => $seller->id]);

        $order = $this->order();

        app(PaymentService::class)->record($order, ['amount' => $order->total, 'method' => 'esewa']);
        $order->fresh()->update(['status' => 'out_for_delivery']);

        $this->assertEquals(0, $seller->fresh()->unpaid_earnings);

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/v1/orders/'.$order->order_number.'/received')->assertOk();

        $this->assertGreaterThan(0, $seller->fresh()->unpaid_earnings);
    }

    public function test_it_cannot_be_confirmed_before_it_is_on_its_way(): void
    {
        $order = $this->order();

        app(PaymentService::class)->record($order, ['amount' => $order->total, 'method' => 'esewa']);

        $this->assertFalse($order->fresh()->canConfirmReceipt());

        $this->postJson('/api/v1/orders/'.$order->order_number.'/received')
            ->assertStatus(422);

        $this->assertNotSame('delivered', $order->fresh()->status);
    }

    /** An unpaid order closes itself when the driver takes the cash. */
    public function test_an_order_still_owing_money_is_not_offered_the_button(): void
    {
        $order = $this->order('advance');
        $order->update(['status' => 'out_for_delivery']);

        $this->assertFalse(
            $this->getJson('/api/v1/orders/'.$order->order_number)->json('data.can_confirm_receipt')
        );

        $this->postJson('/api/v1/orders/'.$order->order_number.'/received')
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is still a balance to pay on this order. '
                .'Settle it with the driver and it will close itself.');
    }

    public function test_a_buyer_cannot_close_someone_elses_order(): void
    {
        $order = $this->order();

        app(PaymentService::class)->record($order, ['amount' => $order->total, 'method' => 'esewa']);
        $order->fresh()->update(['status' => 'out_for_delivery']);

        $stranger = User::where('role', 'customer')->where('id', '!=', $this->buyer->id)->firstOrFail();
        Sanctum::actingAs($stranger);

        $this->postJson('/api/v1/orders/'.$order->order_number.'/received')->assertNotFound();

        $this->assertSame('out_for_delivery', $order->fresh()->status);
    }

    public function test_confirming_twice_is_harmless(): void
    {
        $order = $this->order();

        app(PaymentService::class)->record($order, ['amount' => $order->total, 'method' => 'esewa']);
        $order->fresh()->update(['status' => 'out_for_delivery']);

        $this->postJson('/api/v1/orders/'.$order->order_number.'/received')->assertOk();
        $this->postJson('/api/v1/orders/'.$order->order_number.'/received')->assertOk();

        $this->assertSame(
            1,
            Order::find($order->id)->statusHistories()->where('to_status', 'delivered')->count()
        );
    }
}
