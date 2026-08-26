<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Choosing how much to pay, and when, at the moment the order is placed.
 *
 * The shop should not be holding an animal for someone who has not put
 * anything down, so the buyer commits to a plan at checkout and is asked for
 * the money straight away.
 */
class CheckoutPaymentPlanTest extends TestCase
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
            'name' => 'Plan Test Goat',
            'gender' => 'male',
            'price' => 40000,
            'stock' => 5,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        PaymentMethod::where('code', 'esewa')->update([
            'is_active' => true,
            'payee_account_name' => 'Goat Haven Pvt Ltd',
            'payee_account_number' => '9800000000',
        ]);

        Setting::where('key', 'advance_percent')->first()?->update(['value' => '30']);
    }

    /**
     * A method a buyer may choose but cannot send money to yet — the shop
     * takes it at the counter. Cash on delivery no longer fills this role
     * because it cannot start an order at all.
     */
    private function counterMethod(): PaymentMethod
    {
        return PaymentMethod::create([
            'code' => 'counter',
            'name' => 'Pay at the farm',
            'is_active' => true,
            'on_delivery_only' => false,
            'sort_order' => 9,
        ]);
    }

    private function checkout(string $method, ?string $plan): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        return $this->postJson('/api/v1/checkout', array_filter([
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => $method,
            'payment_plan' => $plan,
        ]));
    }

    public function test_checkout_offers_the_plans_each_method_allows(): void
    {
        Sanctum::actingAs($this->buyer);

        $methods = collect($this->getJson('/api/v1/checkout/options')->assertOk()->json('data.payment_methods'))
            ->keyBy('code');

        // eSewa has an account to send to, so money is wanted up front — the
        // only question is how much of it.
        $this->assertEqualsCanonicalizing(['full', 'advance'], $methods['esewa']['plans']);
        $this->assertNotContains('on_delivery', $methods['esewa']['plans']);

        // Cash on delivery has nowhere to send money before the door.
        $this->assertSame(['on_delivery'], $methods['cod']['plans']);
    }

    public function test_paying_in_full_is_wanted_as_soon_as_the_order_exists(): void
    {
        $number = $this->checkout('esewa', 'full')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        $this->assertSame('full', $order->payment_plan);
        $this->assertEquals($order->total, $order->advance_required);

        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        // Still 'pending' — no dispatch needed for the money to be due.
        $this->assertSame('pending', $order->status);
        $this->assertTrue($payment['is_due']);
        $this->assertTrue($payment['can_pay_now']);
        $this->assertEqualsWithDelta((float) $order->total, $payment['amount_due_now'], 0.01);
    }

    public function test_an_advance_asks_for_the_configured_share_only(): void
    {
        $number = $this->checkout('esewa', 'advance')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        $expected = round((float) $order->total * 0.30, 2);

        $this->assertSame('advance', $order->payment_plan);
        $this->assertEqualsWithDelta($expected, (float) $order->advance_required, 0.01);

        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertTrue($payment['is_due']);
        $this->assertTrue($payment['awaiting_advance']);
        // The advance, not the whole order.
        $this->assertEqualsWithDelta($expected, $payment['amount_due_now'], 0.01);
        $this->assertEqualsWithDelta((float) $order->total, $payment['balance_due'], 0.01);
    }

    public function test_the_advance_percentage_is_a_setting(): void
    {
        Setting::where('key', 'advance_percent')->first()->update(['value' => '50']);

        $number = $this->checkout('esewa', 'advance')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        $this->assertEqualsWithDelta((float) $order->total * 0.5, (float) $order->advance_required, 0.01);
    }

    public function test_a_method_with_its_own_fixed_advance_overrides_the_percentage(): void
    {
        PaymentMethod::where('code', 'esewa')
            ->update(['advance_amount' => 5000, 'advance_type' => 'fixed']);

        $number = $this->checkout('esewa', 'advance')->assertCreated()->json('data.order_number');

        $this->assertEquals(5000, Order::where('order_number', $number)->firstOrFail()->advance_required);
    }

    public function test_once_the_advance_is_in_the_rest_waits_for_delivery(): void
    {
        $number = $this->checkout('esewa', 'advance')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        app(PaymentService::class)->record($order, [
            'amount' => $order->advance_required,
            'method' => 'esewa',
        ]);

        $order = $order->fresh();
        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertFalse($payment['awaiting_advance']);

        // "Rest on delivery" means the buyer is not chased for the balance
        // online — it is the rider's to collect.
        $this->assertFalse($payment['is_due'], 'The balance should wait for delivery');
        $this->assertFalse($payment['can_pay_now'], 'The pay form should be gone');
        $this->assertTrue($payment['settled_until_delivery']);
        $this->assertFalse($order->canBeDelivered());

        // Once it is actually on its way, the balance can be settled either way.
        $order->update(['status' => 'out_for_delivery']);

        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertTrue($payment['is_due']);
        $this->assertTrue($payment['can_pay_now']);
        $this->assertEqualsWithDelta($order->fresh()->balance_due, $payment['amount_due_now'], 0.01);
    }

    public function test_a_method_with_no_account_cannot_promise_to_pay_up_front(): void
    {
        $this->counterMethod();

        $this->checkout('counter', 'full')
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_plan');
    }

    public function test_leaving_the_plan_out_keeps_the_old_behaviour(): void
    {
        $this->counterMethod();

        $number = $this->checkout('counter', null)->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->firstOrFail();

        $this->assertSame('on_delivery', $order->payment_plan);
        $this->assertNull($order->advance_required);
    }

    public function test_a_method_can_set_its_advance_as_a_percentage(): void
    {
        PaymentMethod::where('code', 'esewa')
            ->update(['advance_amount' => 25, 'advance_type' => 'percent']);

        $number = $this->checkout('esewa', 'advance')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        // A quarter of the order, not 25 rupees.
        $this->assertEqualsWithDelta((float) $order->total * 0.25, (float) $order->advance_required, 0.01);
        $this->assertGreaterThan(100, (float) $order->advance_required);
    }

    public function test_the_same_number_as_a_fixed_amount_means_rupees(): void
    {
        PaymentMethod::where('code', 'esewa')
            ->update(['advance_amount' => 25, 'advance_type' => 'fixed']);

        $number = $this->checkout('esewa', 'advance')->assertCreated()->json('data.order_number');

        $this->assertEquals(25, Order::where('order_number', $number)->firstOrFail()->advance_required);
    }

    public function test_an_advance_never_exceeds_the_order(): void
    {
        PaymentMethod::where('code', 'esewa')
            ->update(['advance_amount' => 500000, 'advance_type' => 'fixed']);

        $number = $this->checkout('esewa', 'advance')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        $this->assertEquals($order->total, $order->advance_required);
    }

    /** Insisting on money up front takes pay-on-delivery off the table. */
    public function test_requiring_payment_up_front_removes_the_pay_later_option(): void
    {
        PaymentMethod::where('code', 'esewa')->update(['requires_advance' => true]);

        Sanctum::actingAs($this->buyer);

        $methods = collect($this->getJson('/api/v1/checkout/options')->assertOk()->json('data.payment_methods'))
            ->keyBy('code');

        $this->assertEqualsCanonicalizing(['full', 'advance'], $methods['esewa']['plans']);

        $this->checkout('esewa', 'on_delivery')
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_plan');
    }

    /**
     * The setting must not be silently dropped when there is no account yet:
     * the order still carries the obligation, staff just arrange collection.
     */
    public function test_requiring_payment_up_front_survives_a_missing_payee_account(): void
    {
        $method = $this->counterMethod();
        $method->update(['requires_advance' => true]);

        $method->refresh();

        $this->assertFalse($method->isPrepayable());
        $this->assertSame(['advance'], $method->paymentPlans());

        $number = $this->checkout('counter', 'advance')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        $this->assertSame('advance', $order->payment_plan);
        $this->assertGreaterThan(0, (float) $order->advance_required);

        // Owed straight away. The buyer can still settle it through any other
        // method that does have an account, which is why eSewa is offered here.
        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertTrue($payment['is_due']);
        $this->assertTrue($payment['can_pay_now']);
        $this->assertContains('esewa', array_column($payment['methods'], 'code'));
    }

    public function test_cash_on_delivery_is_shown_but_cannot_start_an_order(): void
    {
        Sanctum::actingAs($this->buyer);

        $methods = collect($this->getJson('/api/v1/checkout/options')->assertOk()->json('data.payment_methods'))
            ->keyBy('code');

        // Still listed, so the buyer understands how they settle up...
        $this->assertArrayHasKey('cod', $methods);
        $this->assertFalse($methods['cod']['selectable']);
        $this->assertStringContainsString('settle', $methods['cod']['unavailable_reason']);

        // ...but the wallets are what actually place an order.
        $this->assertTrue($methods['esewa']['selectable']);
    }

    public function test_placing_an_order_on_a_delivery_only_method_is_refused(): void
    {
        $this->checkout('cod', null)
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Order::count());
    }

    /** Staff still settle the balance in cash against it at the door. */
    public function test_cash_on_delivery_still_takes_money_after_the_advance(): void
    {
        $number = $this->checkout('esewa', 'advance')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        app(PaymentService::class)->record($order, [
            'amount' => $order->advance_required,
            'method' => 'esewa',
        ]);

        $order = $order->fresh();
        $this->assertSame('partially_paid', $order->payment_status);

        $order->update(['status' => 'out_for_delivery']);

        // The rider brings the rest back in cash.
        app(PaymentService::class)->record($order->fresh(), [
            'amount' => $order->fresh()->balance_due,
            'method' => 'cod',
        ]);

        $order = $order->fresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('delivered', $order->status);
        $this->assertEqualsCanonicalizing(
            ['esewa', 'cod'],
            $order->payments()->pluck('method')->all()
        );
    }

    /**
     * A shop must always be able to take an order.
     *
     * A fresh install ships with cash on delivery as the only active method, so
     * marking it delivery-only must not leave the checkout with nothing at all.
     */
    public function test_a_delivery_only_method_stands_in_when_it_is_the_only_one(): void
    {
        // Back to a bare shop: cash on delivery and nothing else.
        PaymentMethod::where('code', '!=', 'cod')->update(['is_active' => false]);

        $cod = PaymentMethod::where('code', 'cod')->firstOrFail();

        $this->assertTrue($cod->on_delivery_only);
        $this->assertTrue($cod->isCheckoutSelectable(), 'The shop would have no way to take an order');

        $this->checkout('cod', null)->assertCreated();

        // Switch a wallet back on and it steps aside again.
        PaymentMethod::where('code', 'esewa')->update(['is_active' => true]);

        $this->assertFalse(PaymentMethod::where('code', 'cod')->firstOrFail()->isCheckoutSelectable());
    }

    /** Paying in full is asked for once and then never again. */
    public function test_a_full_payment_plan_keeps_asking_until_it_is_settled(): void
    {
        $number = $this->checkout('esewa', 'full')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        $half = round((float) $order->total / 2, 2);

        app(PaymentService::class)->record($order, ['amount' => $half, 'method' => 'esewa']);

        // Half paid on a pay-in-full order still owes the other half now.
        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertTrue($payment['is_due']);
        $this->assertFalse($payment['settled_until_delivery']);
        $this->assertEqualsWithDelta($half, $payment['amount_due_now'], 0.01);

        app(PaymentService::class)->record($order->fresh(), ['amount' => $half, 'method' => 'esewa']);

        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertTrue($payment['is_paid']);
        $this->assertFalse($payment['can_pay_now']);
    }

    /**
     * The panel has to say what was actually chosen.
     *
     * A pay-in-full order sets its up-front amount to the whole total, so
     * `awaiting_advance` is true for it as well — reading that flag as "this is
     * an advance" put "Pay your advance ... the remaining Rs 0 is due when it
     * arrives" on a full payment. `due_kind` says it outright instead.
     */
    public function test_a_full_payment_is_never_described_as_an_advance(): void
    {
        $number = $this->checkout('esewa', 'full')->assertCreated()->json('data.order_number');

        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertSame('full', $payment['plan']);
        $this->assertSame('full', $payment['due_kind']);
        // The misleading flag is still true here, which is exactly why the
        // storefront must not be the thing deciding.
        $this->assertTrue($payment['awaiting_advance']);
    }

    public function test_an_advance_order_says_it_is_an_advance(): void
    {
        $number = $this->checkout('esewa', 'advance')->assertCreated()->json('data.order_number');

        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertSame('advance', $payment['due_kind']);
    }

    /** Part paid, and now being asked for what is left. */
    public function test_the_remainder_is_described_as_a_balance(): void
    {
        $number = $this->checkout('esewa', 'full')->assertCreated()->json('data.order_number');
        $order = Order::where('order_number', $number)->firstOrFail();

        app(PaymentService::class)->record($order, [
            'amount' => round((float) $order->total / 2, 2),
            'method' => 'esewa',
        ]);

        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertSame('balance', $payment['due_kind']);
        $this->assertSame('partially_paid', $order->fresh()->payment_status);
    }

}
