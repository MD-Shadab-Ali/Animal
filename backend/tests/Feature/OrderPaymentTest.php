<?php

namespace Tests\Feature;


use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Money in, and the order status that follows from it.
 *
 * The rule the whole thing rests on: an order is not delivered until it is
 * paid for, and once it is paid for nobody has to say so by hand.
 */
class OrderPaymentTest extends TestCase
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
            'name' => 'Payable Black Bengal',
            'gender' => 'male',
            'price' => 30000,
            'stock' => 5,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        // An admin has given eSewa an account for buyers to send money to.
        PaymentMethod::where('code', 'esewa')->update([
            'is_active' => true,
            'payee_account_name' => 'Goat Haven Pvt Ltd',
            'payee_account_number' => '9800000000',
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
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            // Cash on delivery settles an order, it cannot start one. The order
            // is placed on the wallet and is unpaid until money is recorded.
            'payment_method' => 'esewa',
            'payment_plan' => 'full',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    public function test_an_unpaid_order_cannot_be_marked_delivered(): void
    {
        $order = $this->placeOrder();

        $this->expectException(ValidationException::class);

        $order->update(['status' => 'delivered']);
    }

    /**
     * Paying a `full` order does not deliver it.
     *
     * Money on a pay-up-front order arrives long before the goat does, so the
     * balance says nothing about whether anything turned up. Someone who was
     * there has to say so -- the buyer has a button, and staff can set it by
     * hand. Closing it here released the seller's earnings for an animal
     * nobody had laid eyes on.
     */
    public function test_paying_in_full_does_not_close_an_order_by_itself(): void
    {
        $order = $this->placeOrder();
        $order->update(['status' => 'out_for_delivery']);

        app(PaymentService::class)->record($order->fresh(), [
            'amount' => $order->total,
            'method' => 'cod',
        ]);

        $order = $order->fresh();

        $this->assertSame('out_for_delivery', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNull($order->delivered_at);

        // And it is waiting on a person, which is what the buyer's
        // confirmation button and the staff queue both key off.
        $this->assertTrue($order->canConfirmReceipt());
    }

    /** Cash on delivery is handed over at the door, so paying does close it. */
    public function test_cash_on_delivery_closes_the_order_when_it_is_collected(): void
    {
        $order = $this->placeOrder();
        $order->update(['status' => 'out_for_delivery', 'payment_plan' => 'on_delivery']);

        app(PaymentService::class)->record($order->fresh(), [
            'amount' => $order->total,
            'method' => 'cod',
        ]);

        $order = $order->fresh();

        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->delivered_at);
    }

    /** Paying for a goat still on the farm does not deliver it. */
    public function test_paying_early_does_not_teleport_the_order_to_delivered(): void
    {
        $order = $this->placeOrder();
        $order->update(['status' => 'confirmed']);

        app(PaymentService::class)->record($order->fresh(), [
            'amount' => $order->total,
            'method' => 'esewa',
        ]);

        $order = $order->fresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
    }

    public function test_auto_delivery_can_be_switched_off(): void
    {
        Setting::where('key', 'auto_deliver_on_payment')->first()->update(['value' => '0']);

        $order = $this->placeOrder();
        $order->update(['status' => 'out_for_delivery']);

        app(PaymentService::class)->record($order->fresh(), [
            'amount' => $order->total,
            'method' => 'cod',
        ]);

        $this->assertSame('out_for_delivery', $order->fresh()->status);
    }

    public function test_the_order_tells_the_buyer_where_to_send_the_money(): void
    {
        $order = $this->placeOrder();
        $order->update(['status' => 'out_for_delivery']);

        $payment = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->json('data.payment');

        $this->assertTrue($payment['can_pay_now']);
        $this->assertEqualsWithDelta((float) $order->total, $payment['balance_due'], 0.01);

        $codes = array_column($payment['methods'], 'code');

        $this->assertContains('esewa', $codes);
        // Cash on delivery has no account to send to, so it is not offered.
        $this->assertNotContains('cod', $codes);

        $esewa = collect($payment['methods'])->firstWhere('code', 'esewa');

        $this->assertSame('9800000000', $esewa['payee']['account_number']);
        $this->assertSame('Goat Haven Pvt Ltd', $esewa['payee']['account_name']);
    }

    public function test_a_buyer_can_declare_a_payment_and_staff_must_confirm_it(): void
    {
        $order = $this->placeOrder();

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
            'transaction_reference' => 'ESW-99887766',
        ])->assertCreated()->json('data.reference');

        $payment = Payment::where('reference', $reference)->firstOrFail();

        // A claim moves nothing on its own.
        $this->assertSame('pending', $payment->status);
        $this->assertSame('customer', $payment->source);
        $this->assertEquals(0, $order->fresh()->paid_amount);
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        $admin = User::where('role', 'admin')->firstOrFail();
        app(PaymentService::class)->confirm($payment, $admin);

        $order = $order->fresh();

        $this->assertEquals($order->total, $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($admin->id, $payment->fresh()->confirmed_by);
    }

    public function test_a_rejected_payment_puts_the_balance_back(): void
    {
        $order = $this->placeOrder();
        $admin = User::where('role', 'admin')->firstOrFail();

        $payment = app(PaymentService::class)->record($order, [
            'amount' => $order->total,
            'method' => 'esewa',
        ], $admin);

        $this->assertSame('paid', $order->fresh()->payment_status);

        app(PaymentService::class)->reject($payment, 'Never arrived.', $admin);

        $order = $order->fresh();

        $this->assertEquals(0, $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
    }

    public function test_two_part_payments_add_up(): void
    {
        $order = $this->placeOrder();
        $half = round((float) $order->total / 2, 2);

        app(PaymentService::class)->record($order->fresh(), ['amount' => $half, 'method' => 'esewa']);

        $this->assertSame('partially_paid', $order->fresh()->payment_status);

        app(PaymentService::class)->record($order->fresh(), ['amount' => $half, 'method' => 'cod']);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertEquals($order->total, $order->fresh()->paid_amount);
    }

    public function test_a_buyer_cannot_pay_more_than_they_owe(): void
    {
        $order = $this->placeOrder();

        $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => (float) $order->total + 5000,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_a_buyer_cannot_pay_for_someone_elses_order(): void
    {
        $order = $this->placeOrder();

        $stranger = User::where('role', 'customer')->where('id', '!=', $this->buyer->id)->firstOrFail();
        Sanctum::actingAs($stranger);

        $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => 100,
        ])->assertNotFound();
    }

    public function test_a_refund_subtracts_from_what_was_received(): void
    {
        $order = $this->placeOrder();

        app(PaymentService::class)->record($order->fresh(), ['amount' => $order->total, 'method' => 'esewa']);
        app(PaymentService::class)->record($order->fresh(), [
            'amount' => $order->total,
            'method' => 'esewa',
            'type'   => 'refund',
        ]);

        $order = $order->fresh();

        $this->assertEquals(0, $order->paid_amount);
        $this->assertSame('refunded', $order->payment_status);
    }

    public function test_staff_can_see_and_action_a_payment_in_the_panel(): void
    {
        $order = $this->placeOrder();

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
            'transaction_reference' => 'ESW-12341234',
        ])->assertCreated()->json('data.reference');

        $payment = Payment::where('reference', $reference)->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();

        // Named guard: acting as the buyer through Sanctum made it the default.
        $this->actingAs($admin, 'web')
            ->get('/admin/payments')
            ->assertOk()
            ->assertSee($reference)
            ->assertSee('ESW-12341234');

        $this->actingAs($admin, 'web')
            ->get('/admin/payments/'.$payment->getKey())
            ->assertOk()
            ->assertSee($order->order_number);

        // The order screen carries the same ledger.
        $this->actingAs($admin, 'web')
            ->get('/admin/orders/'.$order->getKey().'/edit')
            ->assertOk();
    }

    public function test_the_seller_is_not_offered_delivered_while_the_order_is_unpaid(): void
    {
        $seller = \App\Models\Seller::create([
            'user_id' => User::create([
                'name' => 'Payment Farm', 'email' => 'payfarm@example.test',
                'phone' => '+977 9800-333333', 'password' => 'password',
                'role' => 'customer', 'is_active' => true,
            ])->id,
            'farm_name' => 'Payment Farm',
            'contact_phone' => '+977 9800-333333',
            'city' => 'Kathmandu',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->goat->update(['seller_id' => $seller->id]);

        $order = $this->placeOrder();
        $order->update(['status' => 'out_for_delivery']);

        Sanctum::actingAs($seller->user);

        $next = $this->getJson('/api/v1/seller/orders')->assertOk()->json('data.0.next_status');

        $this->assertNotContains('delivered', array_column($next, 'value'));
        $this->assertTrue($this->getJson('/api/v1/seller/orders')->json('data.0.awaiting_payment'));
    }

    /**
     * A pay-on-delivery order is only asked once the goat is on its way.
     *
     * That plan now only arises on a method with no account to pay into —
     * anything that can take money online must take some of it up front.
     */
    public function test_a_pay_on_delivery_order_is_asked_at_dispatch(): void
    {
        PaymentMethod::create([
            'code' => 'counter', 'name' => 'Pay at the farm',
            'is_active' => true, 'on_delivery_only' => false, 'sort_order' => 9,
        ]);

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'counter',
        ])->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->firstOrFail();

        $this->assertSame('on_delivery', $order->payment_plan);

        foreach (['pending', 'confirmed', 'processing'] as $status) {
            $order->update(['status' => $status]);

            $payment = $this->getJson('/api/v1/orders/'.$order->order_number)
                ->assertOk()
                ->json('data.payment');

            $this->assertFalse($payment['is_due'], "Payment should not be due at {$status}");
        }

        $order->update(['status' => 'out_for_delivery']);

        $payment = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->json('data.payment');

        $this->assertTrue($payment['is_due']);
        $this->assertTrue($payment['can_pay_now']);
    }

    /**
     * The case that made the panel look missing: the order is owed, but no
     * admin has given any method an account to receive money into.
     */
    public function test_an_order_with_nowhere_to_pay_says_so_instead_of_going_blank(): void
    {
        // Placed while an account existed, then the account is taken away —
        // the money is still owed, there is just nowhere to send it.
        $order = $this->placeOrder();

        PaymentMethod::query()->update([
            'payee_account_name'   => null,
            'payee_account_number' => null,
            'payee_qr_image'       => null,
        ]);

        $order->update(['status' => 'out_for_delivery']);

        $payment = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->json('data.payment');

        $this->assertTrue($payment['is_due']);
        $this->assertFalse($payment['can_pay_now']);
        $this->assertTrue($payment['awaiting_setup']);
        $this->assertSame([], $payment['methods']);
    }

    /** An advance asked for at checkout is payable straight away. */
    public function test_an_outstanding_advance_can_be_paid_before_dispatch(): void
    {
        PaymentMethod::where('code', 'esewa')
            ->update(['requires_advance' => true, 'advance_amount' => 5000]);

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'esewa',
        ])->assertCreated()->json('data.order_number');

        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        // Still only 'pending', but an advance is owed, so it can be paid now.
        $this->assertTrue($payment['is_due']);
        $this->assertTrue($payment['can_pay_now']);
    }

    /** Staff must be able to see who paid for which animal, without digging. */
    public function test_a_payment_names_the_buyer_and_the_goat(): void
    {
        $order = $this->placeOrder();
        $order->update(['status' => 'out_for_delivery']);

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
            'transaction_reference' => 'ESW-55556666',
        ])->assertCreated()->json('data.reference');

        $payment = Payment::where('reference', $reference)->firstOrFail();

        $this->assertSame('Payable Black Bengal', $payment->goats_summary);
        $this->assertSame($this->buyer->id, $payment->user_id);

        $admin = User::where('role', 'admin')->firstOrFail();

        // The list answers it at a glance...
        $this->actingAs($admin, 'web')
            ->get('/admin/payments')
            ->assertOk()
            ->assertSee($this->buyer->name)
            ->assertSee('Payable Black Bengal')
            ->assertSee($order->order_number);

        // ...and the payment itself spells out the line.
        $this->actingAs($admin, 'web')
            ->get('/admin/payments/'.$payment->getKey())
            ->assertOk()
            ->assertSee('Payable Black Bengal')
            ->assertSee($order->customer_name);
    }

    public function test_a_payment_for_several_goats_says_how_many(): void
    {
        Sanctum::actingAs($this->buyer);

        $second = Goat::create([
            'category_id' => Category::first()->id,
            'name' => 'Second Payable Goat',
            'gender' => 'female',
            'price' => 15000,
            'stock' => 2,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();
        $this->postJson('/api/v1/cart', ['goat_id' => $second->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            // Cash on delivery settles an order, it cannot start one. The order
            // is placed on the wallet and is unpaid until money is recorded.
            'payment_method' => 'esewa',
            'payment_plan' => 'full',
        ])->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->firstOrFail();

        $payment = app(PaymentService::class)->record($order, [
            'amount' => $order->total,
            'method' => 'cod',
        ]);

        $this->assertStringContainsString('+1 more', $payment->goats_summary);
        $this->assertCount(2, $payment->goats());
    }

    /** Once told, stop asking: the form goes away until staff have ruled. */
    public function test_the_pay_form_steps_aside_while_a_claim_is_open(): void
    {
        $order = $this->placeOrder();

        $before = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.payment');

        $this->assertTrue($before['can_pay_now']);
        $this->assertFalse($before['awaiting_check']);

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
            'transaction_reference' => 'ESW-0011EF7K',
        ])->assertCreated()->json('data.reference');

        $after = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.payment');

        $this->assertTrue($after['awaiting_check']);
        $this->assertFalse($after['can_pay_now'], 'The form should be hidden while a claim is open');
        $this->assertFalse($after['awaiting_setup']);
        $this->assertEqualsWithDelta((float) $order->total, $after['submitted_amount'], 0.01);

        // Hiding the form is not the guard — the API refuses a repeat too.
        $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(1, Payment::where('order_id', $order->id)->count());

        // Rejected, and the buyer may try again.
        app(PaymentService::class)->reject(
            Payment::where('reference', $reference)->firstOrFail(),
            'Nothing arrived.',
            User::where('role', 'admin')->firstOrFail()
        );

        $reopened = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.payment');

        $this->assertFalse($reopened['awaiting_check']);
        $this->assertTrue($reopened['can_pay_now']);
    }

    /** A confirmed payment closes the question rather than reopening the form. */
    public function test_a_confirmed_claim_leaves_nothing_to_pay(): void
    {
        $order = $this->placeOrder();

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
        ])->assertCreated()->json('data.reference');

        app(PaymentService::class)->confirm(
            Payment::where('reference', $reference)->firstOrFail(),
            User::where('role', 'admin')->firstOrFail()
        );

        $payment = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.payment');

        $this->assertFalse($payment['awaiting_check']);
        $this->assertFalse($payment['can_pay_now']);
        $this->assertTrue($payment['is_paid']);
    }

    /** Confirming the money is the same act as confirming the order. */
    public function test_confirming_a_payment_confirms_the_order(): void
    {
        $order = $this->placeOrder();

        $this->assertSame('pending', $order->status);

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
            'transaction_reference' => 'ESW-0011EF7K',
        ])->assertCreated()->json('data.reference');

        // A claim alone proves nothing, so the order has not moved.
        $this->assertSame('pending', $order->fresh()->status);

        app(PaymentService::class)->confirm(
            Payment::where('reference', $reference)->firstOrFail(),
            User::where('role', 'admin')->firstOrFail()
        );

        $this->assertSame('confirmed', $order->fresh()->status);

        // And the move is written to the order's history like any other.
        $this->assertDatabaseHas('order_status_histories', [
            'order_id'    => $order->id,
            'from_status' => 'pending',
            'to_status'   => 'confirmed',
        ]);
    }

    public function test_a_rejected_payment_leaves_the_order_where_it_was(): void
    {
        $order = $this->placeOrder();

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
        ])->assertCreated()->json('data.reference');

        app(PaymentService::class)->reject(
            Payment::where('reference', $reference)->firstOrFail(),
            'Nothing arrived.',
            User::where('role', 'admin')->firstOrFail()
        );

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** Half an advance is not an advance, so the order stays merely placed. */
    public function test_a_short_payment_does_not_confirm_the_order(): void
    {
        $order = $this->placeOrder();

        app(PaymentService::class)->record($order, [
            'amount' => round((float) $order->total / 4, 2),
            'method' => 'esewa',
        ]);

        $order = $order->fresh();

        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertSame('pending', $order->status, 'Only part of what was owed up front arrived');

        // The rest of it lands and the order is committed.
        app(PaymentService::class)->record($order, [
            'amount' => $order->balance_due,
            'method' => 'esewa',
        ]);

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    /** Confirming money must never drag an order backwards. */
    public function test_confirming_a_payment_does_not_rewind_a_later_order(): void
    {
        $order = $this->placeOrder();
        $order->update(['status' => 'processing']);

        app(PaymentService::class)->record($order->fresh(), [
            'amount' => $order->total,
            'method' => 'esewa',
        ]);

        $this->assertSame('processing', $order->fresh()->status);
    }

    /** The panel and the storefront read one status column, not two. */
    public function test_the_admin_orders_screen_shows_the_auto_confirmation(): void
    {
        $order = $this->placeOrder();
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin, 'web');

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertTableColumnStateSet('status', 'pending', $order);

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
        ])->assertCreated()->json('data.reference');

        $this->actingAs($admin, 'web');

        app(PaymentService::class)->confirm(
            Payment::where('reference', $reference)->firstOrFail(),
            $admin
        );

        // Same column, so the admin list moves with it.
        Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('status', 'confirmed', $order->fresh());

        // And so does the order's own edit screen.
        $this->get('/admin/orders/'.$order->getKey().'/edit')
            ->assertOk()
            ->assertSee('Confirmed');
    }

    /**
     * The screenshot is the evidence staff check a claim against, so it has to
     * reach them. It was being stored and then never rendered anywhere.
     */
    public function test_the_receipt_the_buyer_attached_reaches_the_admin_panel(): void
    {
        Storage::fake('public');

        $order = $this->placeOrder();

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
            'transaction_reference' => 'ESW-778899',
            'proof' => UploadedFile::fake()->image('receipt.png'),
        ])->assertCreated()->json('data.reference');

        $payment = Payment::where('reference', $reference)->firstOrFail();

        // Stored...
        $this->assertTrue($payment->hasProof());
        $this->assertTrue($payment->proofIsImage());
        Storage::disk('public')->assertExists($payment->proof);

        // ...and actually shown, not just kept.
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin, 'web')
            ->get('/admin/payments/'.$payment->getKey())
            ->assertOk()
            ->assertSee($payment->proof, false)
            ->assertSee('ESW-778899');

        $this->actingAs($admin, 'web')
            ->get('/admin/payments')
            ->assertOk()
            ->assertSee($payment->proof, false);
    }

    public function test_a_claim_without_a_receipt_says_so_rather_than_breaking(): void
    {
        $order = $this->placeOrder();

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/payments', [
            'method' => 'esewa',
            'amount' => $order->total,
        ])->assertCreated()->json('data.reference');

        $payment = Payment::where('reference', $reference)->firstOrFail();

        $this->assertFalse($payment->hasProof());
        $this->assertNull($payment->proof_url);

        $this->actingAs(User::where('role', 'admin')->firstOrFail(), 'web')
            ->get('/admin/payments/'.$payment->getKey())
            ->assertOk()
            ->assertSee('No receipt was attached');
    }

    /**
     * Money cannot witness a delivery.
     *
     * An advance order closes itself because the last payment *is* the delivery
     * event — the rider took cash at the door. An order paid in full at checkout
     * has no such signal, so it waits for a person. That is correct, but it must
     * not be invisible: until someone confirms it, the seller's earnings never
     * settle.
     */
    public function test_a_prepaid_order_waits_for_a_person_and_is_surfaced(): void
    {
        $order = $this->placeOrder();

        app(PaymentService::class)->record($order, [
            'amount' => $order->total,
            'method' => 'esewa',
        ]);

        $order->fresh()->update(['status' => 'out_for_delivery']);
        $order = $order->fresh();

        // Nothing is blocking it — and nothing will close it either.
        $this->assertTrue($order->isFullyPaid());
        $this->assertTrue($order->canBeDelivered());
        $this->assertSame('out_for_delivery', $order->status);

        // So it shows up in the queue that needs a human.
        $this->assertTrue(
            Order::query()->awaitingDeliveryConfirmation()->whereKey($order->id)->exists()
        );

        $admin = User::where('role', 'admin')->firstOrFail();

        Livewire::actingAs($admin);
        Livewire::test(ListOrders::class, ['activeTab' => 'to_confirm'])
            ->assertCanSeeTableRecords([$order]);

        // A person confirms it, and only then does it close.
        $order->update(['status' => 'delivered']);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertFalse(
            Order::query()->awaitingDeliveryConfirmation()->whereKey($order->id)->exists()
        );
    }

    /** An unpaid order out for delivery is not waiting on a person, but on money. */
    public function test_an_unpaid_order_is_not_in_the_confirm_queue(): void
    {
        $order = $this->placeOrder();
        $order->update(['status' => 'out_for_delivery']);

        $this->assertFalse(
            Order::query()->awaitingDeliveryConfirmation()->whereKey($order->id)->exists()
        );
    }

}
