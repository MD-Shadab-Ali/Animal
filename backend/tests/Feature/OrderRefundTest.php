<?php

namespace Tests\Feature;

use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\SellerOrderCancelledNotification;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cancelling an order you have already paid towards.
 *
 * The money does not evaporate because the order did: it stays on the books as
 * something owed back until staff have actually sent it.
 */
class OrderRefundTest extends TestCase
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
            'name' => 'Refundable Buck',
            'gender' => 'male',
            'price' => 50000,
            'stock' => 3,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        PaymentMethod::where('code', 'esewa')->update([
            'is_active' => true,
            'supports_payout' => true,
            'payee_account_name' => 'Goat Haven Pvt Ltd',
            'payee_account_number' => '9800000000',
        ]);
    }

    /** An order with an advance paid, then cancelled. */
    private function cancelledPaidOrder(): Order
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
            'payment_plan' => 'advance',
        ])->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->firstOrFail();

        app(PaymentService::class)->record($order, [
            'amount' => $order->advance_required,
            'method' => 'esewa',
        ]);

        $this->postJson('/api/v1/orders/'.$number.'/cancel')->assertOk();

        return $order->fresh();
    }

    public function test_a_cancelled_paid_order_owes_the_money_back(): void
    {
        $order = $this->cancelledPaidOrder();

        $this->assertSame('cancelled', $order->status);
        // The money genuinely arrived, so the ledger still says so.
        $this->assertEquals($order->advance_required, $order->paid_amount);
        $this->assertTrue($order->isRefundable());

        $refund = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.refund');

        $this->assertEqualsWithDelta((float) $order->paid_amount, $refund['amount'], 0.01);
        $this->assertTrue($refund['can_request']);
        $this->assertFalse($refund['requested']);
        $this->assertNotEmpty($refund['methods'], 'There must be a rail to send it back on');
    }

    public function test_an_unpaid_cancelled_order_owes_nothing(): void
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
            'payment_plan' => 'full',
        ])->assertCreated()->json('data.order_number');

        $this->postJson('/api/v1/orders/'.$number.'/cancel')->assertOk();

        $refund = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.refund');

        $this->assertEquals(0, $refund['amount']);
        $this->assertFalse($refund['can_request']);
    }

    public function test_a_buyer_can_ask_for_their_money_back(): void
    {
        $order = $this->cancelledPaidOrder();

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'esewa',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
            'refund_reason' => 'Changed my mind.',
        ])->assertCreated()->json('data.reference');

        $refund = Payment::where('reference', $reference)->firstOrFail();

        $this->assertSame('refund', $refund->type);
        $this->assertSame('pending', $refund->status);
        $this->assertSame('Refund requested', $refund->status_label);
        $this->assertEquals($order->paid_amount, $refund->amount);
        $this->assertStringContainsString('9801234567', $refund->refund_destination);

        // Asking changes nothing on the ledger — the money has not moved yet.
        $this->assertEquals($order->paid_amount, $order->fresh()->paid_amount);

        // And the form gives way to a "we are sending it" state.
        $block = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.refund');

        $this->assertTrue($block['requested']);
        $this->assertFalse($block['can_request']);
    }

    public function test_asking_twice_is_refused(): void
    {
        $order = $this->cancelledPaidOrder();

        $body = [
            'method' => 'esewa',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
        ];

        $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', $body)->assertCreated();

        $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund');

        $this->assertSame(1, Payment::refunds()->where('order_id', $order->id)->count());
    }

    public function test_a_live_order_cannot_be_refunded(): void
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
            'payment_plan' => 'full',
        ])->assertCreated()->json('data.order_number');

        $this->postJson('/api/v1/orders/'.$number.'/refunds', [
            'method' => 'esewa',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
        ])->assertStatus(422)->assertJsonValidationErrors('refund');
    }

    public function test_sending_the_refund_clears_the_debt(): void
    {
        $order = $this->cancelledPaidOrder();
        $owed = (float) $order->paid_amount;

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'esewa',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
        ])->assertCreated()->json('data.reference');

        app(PaymentService::class)->confirm(
            Payment::where('reference', $reference)->firstOrFail(),
            User::where('role', 'admin')->firstOrFail()
        );

        $order = $order->fresh();

        $this->assertEquals(0, $order->paid_amount);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertFalse($order->isRefundable());
        $this->assertSame('Refunded', Payment::where('reference', $reference)->firstOrFail()->status_label);

        $block = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.refund');

        $this->assertEquals(0, $block['amount']);
        $this->assertEqualsWithDelta($owed, $block['sent'], 0.01);
        $this->assertFalse($block['can_request']);
    }

    public function test_a_declined_refund_leaves_the_money_where_it_was(): void
    {
        $order = $this->cancelledPaidOrder();
        $owed = (float) $order->paid_amount;

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'esewa',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
        ])->assertCreated()->json('data.reference');

        app(PaymentService::class)->reject(
            Payment::where('reference', $reference)->firstOrFail(),
            'Goat was already collected.',
            User::where('role', 'admin')->firstOrFail()
        );

        $order = $order->fresh();

        $this->assertEquals($owed, $order->paid_amount);
        // Declining reopens the door rather than closing the matter.
        $this->assertTrue($order->isRefundable());
    }

    public function test_the_refund_shows_up_in_its_own_admin_section(): void
    {
        $order = $this->cancelledPaidOrder();

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'esewa',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
            'refund_reason' => 'Changed my mind.',
        ])->assertCreated()->json('data.reference');

        $refund = Payment::where('reference', $reference)->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin, 'web')
            ->get('/admin/refunds')
            ->assertOk()
            ->assertSee($reference)
            ->assertSee('9801234567')
            ->assertSee($order->order_number);

        $this->actingAs($admin, 'web')
            ->get('/admin/refunds/'.$refund->getKey())
            ->assertOk()
            ->assertSee('Rahim Uddin')
            ->assertSee('Changed my mind.');

        // Refunds are their own screen; Payments is money coming in.
        $this->actingAs($admin, 'web')
            ->get('/admin/payments')
            ->assertOk()
            ->assertDontSee($reference);
    }

    public function test_staff_can_send_the_refund_from_the_refunds_screen(): void
    {
        $order = $this->cancelledPaidOrder();

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'esewa',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
        ])->assertCreated()->json('data.reference');

        $refund = Payment::where('reference', $reference)->firstOrFail();

        $this->actingAs(User::where('role', 'admin')->firstOrFail(), 'web');

        Livewire::test(ListRefunds::class)
            ->assertCanSeeTableRecords([$refund])
            ->callTableAction('markRefunded', $refund, ['transaction_reference' => 'ESW-REFUND-1']);

        $refund->refresh();

        $this->assertSame('confirmed', $refund->status);
        $this->assertSame('ESW-REFUND-1', $refund->transaction_reference);
        $this->assertEquals(0, $order->fresh()->paid_amount);
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_a_bank_refund_must_name_the_bank(): void
    {
        PaymentMethod::where('code', 'bank_transfer')
            ->update(['is_active' => true, 'supports_payout' => true, 'requires_bank_name' => true]);

        $order = $this->cancelledPaidOrder();

        // A bank account number is not a destination on its own.
        $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'bank_transfer',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '0123456789',
        ])->assertStatus(422)->assertJsonValidationErrors('refund_to_bank');

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'bank_transfer',
            'refund_to_bank' => 'Nabil Bank',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '0123456789',
        ])->assertCreated()->json('data.reference');

        $this->assertSame('Nabil Bank', Payment::where('reference', $reference)->firstOrFail()->refund_to_bank);
    }

    public function test_a_wallet_refund_is_never_asked_for_a_bank(): void
    {
        // Seeded switched off, so turn it on to compare the two side by side.
        PaymentMethod::where('code', 'bank_transfer')
            ->update(['is_active' => true, 'supports_payout' => true, 'requires_bank_name' => true]);

        $order = $this->cancelledPaidOrder();

        $methods = collect($this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.refund.methods'))->keyBy('code');

        // The storefront hides the bank field off the back of this flag.
        $this->assertFalse($methods['esewa']['needs_bank_name']);
        $this->assertTrue($methods['bank_transfer']['needs_bank_name']);

        $reference = $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'esewa',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
            // Sent anyway by a stale form; a wallet has no bank, so it is dropped.
            'refund_to_bank' => 'Nabil Bank',
        ])->assertCreated()->json('data.reference');

        $this->assertNull(Payment::where('reference', $reference)->firstOrFail()->refund_to_bank);
    }

    public function test_money_cannot_be_sent_back_on_a_rail_we_do_not_use(): void
    {
        $order = $this->cancelledPaidOrder();

        // Cash on delivery is not a way to send money out.
        $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'cod',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
        ])->assertStatus(422)->assertJsonValidationErrors('method');
    }

    public function test_the_buyer_is_told_when_no_rail_is_open(): void
    {
        PaymentMethod::query()->update(['supports_payout' => false]);

        $order = $this->cancelledPaidOrder();

        $refund = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()->json('data.refund');

        $this->assertSame([], $refund['methods']);
        $this->assertGreaterThan(0, $refund['amount']);
    }

    /** A goat is a big purchase; plans change right up to the handover. */
    public function test_a_buyer_can_cancel_at_any_stage_before_delivery(): void
    {
        foreach (['pending', 'confirmed', 'processing', 'out_for_delivery'] as $status) {
            Sanctum::actingAs($this->buyer);

            $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

            $number = $this->postJson('/api/v1/checkout', [
                'customer_name' => 'Rahim Uddin',
                'customer_phone' => '+977 9800-111111',
                'address_line' => 'House 12',
                'city' => 'Kathmandu',
                'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
                'payment_method' => 'esewa',
                'payment_plan' => 'full',
            ])->assertCreated()->json('data.order_number');

            $order = Order::where('order_number', $number)->firstOrFail();
            $order->update(['status' => $status]);

            $this->assertTrue(
                $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.is_cancellable'),
                "An order at {$status} should still be cancellable"
            );

            $this->postJson('/api/v1/orders/'.$number.'/cancel')->assertOk();

            $this->assertSame('cancelled', $order->fresh()->status);
        }
    }

    /** Once it is in the buyer's hands there is nothing left to call off. */
    public function test_a_delivered_order_cannot_be_cancelled(): void
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
            'payment_plan' => 'full',
        ])->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->firstOrFail();

        // Delivery needs the money in, which is the rule everywhere else too.
        app(PaymentService::class)->record($order, ['amount' => $order->total, 'method' => 'esewa']);
        $order->fresh()->update(['status' => 'delivered']);

        $this->assertFalse($order->fresh()->isCancellable());

        $this->postJson('/api/v1/orders/'.$number.'/cancel')->assertStatus(422);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    /** A seller mid-preparation must not find out by noticing a grey line. */
    public function test_the_seller_is_told_when_a_late_order_is_cancelled(): void
    {
        Notification::fake();

        $seller = Seller::create([
            'user_id' => User::create([
                'name' => 'Cancel Farm', 'email' => 'cancelfarm@example.test',
                'phone' => '+977 9800-444444', 'password' => 'password',
                'role' => 'customer', 'is_active' => true,
            ])->id,
            'farm_name' => 'Cancel Farm',
            'contact_phone' => '+977 9800-444444',
            'city' => 'Kathmandu',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->goat->update(['seller_id' => $seller->id]);

        $order = $this->cancelledPaidOrder();

        $this->assertSame('cancelled', $order->status);

        Notification::assertSentTo($seller->user, SellerOrderCancelledNotification::class);
    }

    /**
     * A refund request is a pending row too. Counting it as an incoming payment
     * made a cancelled order announce that we were "checking your payment"
     * right beside "refund on its way".
     */
    public function test_asking_for_a_refund_does_not_look_like_an_incoming_payment(): void
    {
        $order = $this->cancelledPaidOrder();

        $this->postJson('/api/v1/orders/'.$order->order_number.'/refunds', [
            'method' => 'esewa',
            'refund_to_name' => 'Rahim Uddin',
            'refund_to_account' => '9801234567',
        ])->assertCreated();

        $data = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk()->json('data');

        // The refund panel is the only one that should speak.
        $this->assertTrue($data['refund']['requested']);

        $this->assertFalse($data['payment']['awaiting_check'], 'No payment is being checked');
        $this->assertFalse($data['payment']['can_pay_now']);
        $this->assertFalse($data['payment']['awaiting_setup']);
        $this->assertFalse($data['payment']['settled_until_delivery']);
        $this->assertEquals(0, $data['payment']['submitted_amount']);

        // And "what you have paid" stays about payments.
        $this->assertSame(
            ['payment'],
            collect($data['payment']['history'])->pluck('type')->unique()->values()->all()
        );
    }

    /** An order that is off is not waiting on anybody's money. */
    public function test_a_cancelled_order_stops_asking_about_payment(): void
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
            'payment_plan' => 'full',
        ])->assertCreated()->json('data.order_number');

        // A claim filed, then the buyer changes their mind entirely.
        $this->postJson('/api/v1/orders/'.$number.'/payments', [
            'method' => 'esewa',
            'amount' => 1000,
        ])->assertCreated();

        $this->assertTrue(
            $this->getJson('/api/v1/orders/'.$number)->json('data.payment.awaiting_check')
        );

        $this->postJson('/api/v1/orders/'.$number.'/cancel')->assertOk();

        $payment = $this->getJson('/api/v1/orders/'.$number)->assertOk()->json('data.payment');

        $this->assertFalse($payment['awaiting_check']);
        $this->assertFalse($payment['can_pay_now']);
    }
}
