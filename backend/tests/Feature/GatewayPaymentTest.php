<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\GatewayPaymentService;
use App\Services\Gateways\EsewaGateway;
use App\Services\Gateways\KhaltiGateway;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Money that confirms itself.
 *
 * The rule these all circle: nothing the buyer's browser carries back is
 * evidence. A payment is confirmed because the provider, asked directly, said
 * so -- and said so about the right amount.
 */
class GatewayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Goat $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        config([
            'services.esewa.mode'         => 'sandbox',
            'services.esewa.product_code' => 'EPAYTEST',
            'services.esewa.secret'       => '8gBm/:&EnhH.1/q',
            'services.khalti.mode'        => 'sandbox',
            'services.khalti.secret_key'  => 'test_secret_key',
        ]);

        // The seeder ships them switched off; an admin turns them on. A
        // gateway needs no payee account -- the credentials above are what
        // make it usable.
        PaymentMethod::whereIn('code', ['esewa', 'khalti'])->update(['is_active' => true]);

        $this->buyer = User::where('role', 'customer')->firstOrFail();

        $this->goat = Goat::create([
            'category_id'     => Category::first()->id,
            'name'            => 'Gateway Test Buck',
            'gender'          => 'male',
            'price'           => 30000,
            'stock'           => 5,
            'track_stock'     => true,
            'status'          => 'published',
            'approval_status' => 'approved',
        ]);
    }

    private function placeOrder(string $method = 'esewa'): Order
    {
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Rahim Uddin',
            'customer_phone'   => '+977 9800-111111',
            'address_line'     => 'House 12',
            'city'             => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method'   => $method,
            'payment_plan'     => 'full',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    /** Open an attempt directly, without a round trip to the provider. */
    private function attempt(Order $order, ?float $amount = null, string $gateway = 'esewa'): Payment
    {
        return Payment::create([
            'reference'   => app(PaymentService::class)->reference(),
            'order_id'    => $order->id,
            'user_id'     => $this->buyer->id,
            'currency'    => $order->currency,
            'method'      => $gateway,
            'amount'      => $amount ?? $order->total,
            'type'        => 'payment',
            'status'      => 'pending',
            'source'      => 'gateway',
            'gateway'     => $gateway,
            'gateway_ref' => 'REF-'.strtoupper(uniqid()),
        ]);
    }

    /**
     * The signature from eSewa's own worked example.
     *
     * Locked to a known-good pair because getting it wrong fails silently on
     * our side: eSewa simply refuses the form, and the buyer meets an error on
     * a site they have never heard of.
     */
    /**
     * Starting a payment on a fresh order must actually start.
     *
     * The guard here once compared the amount against an accessor that does
     * not exist. It read as null, cast to zero, and every payment looked like
     * an overpayment -- so no buyer could pay at all, and nothing threw.
     */
    public function test_a_payment_can_be_started_for_the_whole_balance(): void
    {
        $order = $this->placeOrder();

        $start = app(GatewayPaymentService::class)
            ->begin($order, $this->buyer, 'esewa', (float) $order->amount_due_now);

        $this->assertSame('form', $start['type']);
        $this->assertSame(
            number_format((float) $order->total, 2, '.', ''),
            $start['fields']['total_amount'],
        );
    }

    public function test_paying_more_than_is_owed_is_refused(): void
    {
        $order = $this->placeOrder();

        $this->expectException(ValidationException::class);

        app(GatewayPaymentService::class)
            ->begin($order, $this->buyer, 'esewa', (float) $order->total + 5000);
    }

    /**
     * A gateway with no credentials must not be offered.
     *
     * It was, and the buyer got an order they could never pay for: Khalti
     * appeared at checkout, the order was placed on it, and opening the
     * payment then failed because there was no key to open it with.
     */
    public function test_a_gateway_without_credentials_is_not_offered_at_checkout(): void
    {
        config(['services.khalti.secret_key' => null]);

        $khalti = PaymentMethod::where('code', 'khalti')->firstOrFail();

        $this->assertFalse($khalti->isGatewayConfigured());
        $this->assertFalse($khalti->isCheckoutSelectable());
        $this->assertFalse($khalti->isPrepayable());

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Rahim Uddin',
            'customer_phone'   => '+977 9800-111111',
            'address_line'     => 'House 12',
            'city'             => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method'   => 'khalti',
            'payment_plan'     => 'full',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    /**
     * A payment that could not be opened leaves nothing behind.
     *
     * The row is written before the buyer is sent away, so that an attempt
     * they abandon is still traceable. When the provider refuses to open one
     * at all, that same row told the buyer we were "checking your payment"
     * for money nobody had asked them for, and counted as an outstanding
     * attempt that blocked the next one.
     */
    public function test_a_gateway_that_will_not_open_leaves_no_pending_payment(): void
    {
        $order = $this->placeOrder();

        config(['services.khalti.secret_key' => null]);

        try {
            app(GatewayPaymentService::class)
                ->begin($order, $this->buyer, 'khalti', (float) $order->amount_due_now);
            $this->fail('Opening a payment without credentials should not succeed.');
        } catch (ValidationException) {
            // Expected: the buyer is told to pick another method.
        }

        $this->assertSame(0, $order->payments()->where('status', 'pending')->count());
    }

    public function test_the_esewa_signature_matches_the_documented_example(): void
    {
        $this->assertSame(
            'i94zsd3oXF6ZsSr/kGqT4sSzYQzjj1W/waxjWyRwaME=',
            app(EsewaGateway::class)->sign('110', '241028'),
        );
    }

    public function test_khalti_amounts_convert_to_and_from_paisa(): void
    {
        // Khalti counts in paisa. A slip here is a hundredfold slip.
        $this->assertSame(2550000, KhaltiGateway::toPaisa(25500.00));
        $this->assertSame(25500.00, KhaltiGateway::toRupees(2550000));
    }

    public function test_a_confirmed_gateway_payment_pays_the_order_with_nobody_confirming_it(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);

        Http::fake(['*rc.esewa.com.np*' => Http::response([
            'product_code'     => 'EPAYTEST',
            'transaction_uuid' => $payment->gateway_ref,
            'total_amount'     => (float) $order->total,
            'status'           => 'COMPLETE',
            'ref_id'           => '0007G36',
        ])]);

        $settled = app(GatewayPaymentService::class)->settle('esewa', $payment->gateway_ref);

        $this->assertSame('confirmed', $settled->status);
        $this->assertSame('0007G36', $settled->transaction_reference);
        // No member of staff signed this off. That is the whole point.
        $this->assertNull($settled->confirmed_by);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_short_payment_is_refused_even_when_the_gateway_says_complete(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);

        // COMPLETE, but for a hundred rupees against a whole order.
        Http::fake(['*rc.esewa.com.np*' => Http::response([
            'transaction_uuid' => $payment->gateway_ref,
            'total_amount'     => 100.0,
            'status'           => 'COMPLETE',
            'ref_id'           => 'SHORT1',
        ])]);

        $settled = app(GatewayPaymentService::class)->settle('esewa', $payment->gateway_ref);

        $this->assertSame('rejected', $settled->status);
        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    public function test_settling_the_same_attempt_repeatedly_confirms_it_once(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);

        Http::fake(['*rc.esewa.com.np*' => Http::response([
            'transaction_uuid' => $payment->gateway_ref,
            'total_amount'     => (float) $order->total,
            'status'           => 'COMPLETE',
            'ref_id'           => '0007G36',
        ])]);

        $gateways = app(GatewayPaymentService::class);

        // The redirect, then an impatient refresh, then the reconcile sweep.
        $gateways->settle('esewa', $payment->gateway_ref);
        $gateways->settle('esewa', $payment->gateway_ref);
        $gateways->settle('esewa', $payment->gateway_ref);

        $this->assertSame(1, $order->payments()->where('status', 'confirmed')->count());
        $this->assertSame((float) $order->total, (float) $order->fresh()->paid_amount);
    }

    public function test_a_payment_still_in_flight_is_left_alone(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);

        Http::fake(['*rc.esewa.com.np*' => Http::response([
            'transaction_uuid' => $payment->gateway_ref,
            'total_amount'     => (float) $order->total,
            'status'           => 'PENDING',
        ])]);

        $settled = app(GatewayPaymentService::class)->settle('esewa', $payment->gateway_ref);

        $this->assertSame('pending', $settled->status);
        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    public function test_khalti_confirms_from_a_lookup(): void
    {
        $order = $this->placeOrder('khalti');
        $payment = $this->attempt($order, gateway: 'khalti');

        Http::fake(['*khalti.com/api/v2/epayment/lookup*' => Http::response([
            'pidx'           => $payment->gateway_ref,
            'total_amount'   => KhaltiGateway::toPaisa((float) $order->total),
            'status'         => 'Completed',
            'transaction_id' => 'GFq9PFS7b2iYvL8Lir9oXe',
            'fee'            => 0,
            'refunded'       => false,
        ])]);

        $settled = app(GatewayPaymentService::class)->settle('khalti', $payment->gateway_ref);

        $this->assertSame('confirmed', $settled->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_cancelled_khalti_payment_is_rejected(): void
    {
        $order = $this->placeOrder('khalti');
        $payment = $this->attempt($order, gateway: 'khalti');

        Http::fake(['*khalti.com/api/v2/epayment/lookup*' => Http::response([
            'pidx'         => $payment->gateway_ref,
            'total_amount' => KhaltiGateway::toPaisa((float) $order->total),
            'status'       => 'User canceled',
        ])]);

        $settled = app(GatewayPaymentService::class)->settle('khalti', $payment->gateway_ref);

        $this->assertSame('rejected', $settled->status);
    }

    /**
     * A buyer cannot hand-declare money a provider will confirm on its own.
     *
     * Allowing both would put a person back in the loop, and double-count the
     * payment the moment the real attempt landed.
     */
    public function test_a_buyer_cannot_declare_a_gateway_payment_by_hand(): void
    {
        $order = $this->placeOrder();

        $this->expectException(ValidationException::class);

        app(PaymentService::class)->claim($order, $this->buyer, [
            'method' => 'esewa',
            'amount' => $order->total,
        ]);
    }

    /** Bank transfer has nobody to ask, so the manual route must survive. */
    public function test_a_buyer_can_still_declare_a_bank_transfer(): void
    {
        // A manual method still needs somewhere to send the money -- that
        // rule is untouched, and is exactly what a gateway no longer needs.
        PaymentMethod::where('code', 'bank_transfer')->update([
            'is_active'            => true,
            'payee_account_name'   => 'Goat Haven Pvt Ltd',
            'payee_account_number' => '0123456789',
            'payee_bank_name'      => 'Nabil Bank',
        ]);

        $order = $this->placeOrder('bank_transfer');

        $payment = app(PaymentService::class)->claim($order, $this->buyer, [
            'method'                => 'bank_transfer',
            'amount'                => $order->total,
            'transaction_reference' => 'NB-99881',
        ]);

        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->gateway);
    }

    /** Staff keep a way in for an outage or a transaction that got stuck. */
    public function test_staff_can_still_record_a_gateway_payment_by_hand(): void
    {
        $order = $this->placeOrder();
        $admin = User::where('role', 'admin')->firstOrFail();

        $payment = app(PaymentService::class)->record($order, [
            'amount' => $order->total,
            'method' => 'esewa',
        ], $admin);

        $this->assertSame('confirmed', $payment->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_the_return_url_sends_the_buyer_back_to_their_order(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);

        Http::fake(['*rc.esewa.com.np*' => Http::response([
            'transaction_uuid' => $payment->gateway_ref,
            'total_amount'     => (float) $order->total,
            'status'           => 'COMPLETE',
            'ref_id'           => '0007G36',
        ])]);

        // eSewa hands its result back as a single base64 blob.
        $data = base64_encode(json_encode([
            'transaction_code' => '0007G36',
            'status'           => 'COMPLETE',
            'total_amount'     => (float) $order->total,
            'transaction_uuid' => $payment->gateway_ref,
            'product_code'     => 'EPAYTEST',
        ]));

        $response = $this->get('/api/v1/payments/esewa/return?data='.urlencode($data));

        $response->assertRedirect();
        $this->assertStringContainsString('/account/orders/'.$order->order_number, $response->headers->get('Location'));
        $this->assertStringContainsString('payment=success', $response->headers->get('Location'));
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    /**
     * The redirect names an attempt; it does not decide the outcome.
     *
     * Here the browser insists the payment succeeded while the provider has no
     * record of it. The provider wins.
     */
    public function test_a_forged_success_redirect_does_not_pay_an_order(): void
    {
        $order = $this->placeOrder();
        $payment = $this->attempt($order);

        Http::fake(['*rc.esewa.com.np*' => Http::response([
            'transaction_uuid' => $payment->gateway_ref,
            'status'           => 'NOT_FOUND',
        ])]);

        $data = base64_encode(json_encode([
            'status'           => 'COMPLETE',
            'total_amount'     => (float) $order->total,
            'transaction_uuid' => $payment->gateway_ref,
        ]));

        $response = $this->get('/api/v1/payments/esewa/return?data='.urlencode($data));

        $this->assertStringContainsString('payment=failed', $response->headers->get('Location'));
        $this->assertNotSame('paid', $order->fresh()->payment_status);
        $this->assertSame('rejected', $payment->fresh()->status);
    }
}
