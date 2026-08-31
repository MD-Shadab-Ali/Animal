<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Payout;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A seller setting up where their money goes, and asking for it.
 *
 * The rails on offer come from the payment methods an admin marked as
 * payout-capable, which is the link that used to be missing: switching a
 * method on in the admin panel had no effect on the earnings page at all.
 */
class SellerPayoutRequestTest extends TestCase
{
    use RefreshDatabase;

    private Seller $seller;
    private User $sellerUser;
    private Goat $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->sellerUser = User::create([
            'name' => 'Karim Farms', 'email' => 'karim@example.test', 'phone' => '+880 1700-222222',
            'password' => 'password', 'role' => 'customer', 'is_active' => true,
        ]);

        $this->seller = Seller::create([
            'user_id' => $this->sellerUser->id,
            'farm_name' => 'Karim Livestock',
            'contact_phone' => '+880 1700-222222',
            'city' => 'Dhaka',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->goat = Goat::create([
            'category_id' => Category::first()->id,
            'seller_id' => $this->seller->id,
            'name' => 'Karim Black Bengal 20kg',
            'gender' => 'male',
            'price' => 30000,
            'stock' => 1,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        // The admin switching a wallet on is what opens payouts.
        PaymentMethod::where('code', 'esewa')->update(['is_active' => true, 'supports_payout' => true]);

        Setting::where('key', 'min_payout_amount')->first()?->update(['value' => '0']);
    }

    private function deliverAnOrder(): Order
    {
        Notification::fake();

        $buyer = User::where('role', 'customer')->where('id', '!=', $this->sellerUser->id)->firstOrFail();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin',
            'customer_email' => 'rahim@example.test',
            'area' => 'Ward 4',
            'postal_code' => '44600',
            'customer_phone' => '+880 1811-111111',
            'address_line' => 'House 12',
            'city' => 'Dhaka',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            // Cash on delivery settles an order, it cannot start one, so the
            // wallet places it and nothing is owed until the goat is on its way.
            'payment_method' => 'esewa',
            'payment_plan' => 'on_delivery',
        ])->assertCreated()->json('data.order_number');

        $order = Order::where('order_number', $number)->firstOrFail();
        $this->markDelivered($order);

        return $order;
    }

    private function saveDetails(): void
    {
        Sanctum::actingAs($this->sellerUser);

        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'esewa',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '9801234567',
        ])->assertOk();
    }

    public function test_only_payout_capable_methods_are_offered(): void
    {
        Sanctum::actingAs($this->sellerUser);

        $codes = $this->getJson('/api/v1/seller/payout-methods')
            ->assertOk()
            ->json('data.*.code');

        $this->assertContains('esewa', $codes);
        // Cash on delivery takes money in; it is not a way to send money out.
        $this->assertNotContains('cod', $codes);
    }

    public function test_a_method_that_is_not_a_payout_rail_is_rejected(): void
    {
        Sanctum::actingAs($this->sellerUser);

        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'cod',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '9801234567',
        ])->assertStatus(422)->assertJsonValidationErrors('payout_method');
    }

    public function test_the_earnings_page_tells_the_seller_to_add_details_first(): void
    {
        $this->deliverAnOrder();
        Sanctum::actingAs($this->sellerUser);

        $payout = $this->getJson('/api/v1/seller/earnings')->assertOk()->json('data.payout');

        $this->assertTrue($payout['accepting']);
        $this->assertFalse($payout['has_details']);
        $this->assertFalse($payout['can_request']);
        $this->assertSame('Add your payout details to get paid.', $payout['blocked_reason']);
    }

    public function test_a_seller_can_request_the_balance_they_are_owed(): void
    {
        $this->deliverAnOrder();
        $this->saveDetails();

        $earnings = $this->getJson('/api/v1/seller/earnings')->assertOk()->json('data');
        $payout   = $earnings['payout'];
        $owed     = $earnings['summary']['unpaid'];

        // Goat earnings after commission, plus the delivery this seller ran.
        $this->assertEquals(28000, $owed);
        $this->assertTrue($payout['can_request']);
        $this->assertNull($payout['blocked_reason']);
        $this->assertSame('eSewa', $payout['method_label']);
        // The full account number never leaves the server.
        $this->assertStringEndsWith('4567', $payout['account_hint']);

        $reference = $this->postJson('/api/v1/seller/payouts')
            ->assertCreated()
            ->json('data.reference');

        $created = Payout::where('reference', $reference)->firstOrFail();

        $this->assertSame('pending', $created->status);
        $this->assertSame('esewa', $created->method);
        $this->assertEquals($owed, $created->amount);
        $this->assertSame($this->sellerUser->id, $created->created_by);
        // The earning is stamped, so it can never be paid out twice.
        $this->assertSame($created->id, $this->seller->orderItems()->first()->payout_id);
    }

    public function test_a_second_request_is_refused_while_one_is_in_flight(): void
    {
        $this->deliverAnOrder();
        $this->saveDetails();

        $this->postJson('/api/v1/seller/payouts')->assertCreated();

        $this->postJson('/api/v1/seller/payouts')
            ->assertStatus(422)
            ->assertJsonValidationErrors('payout');

        $this->assertSame(1, $this->seller->payouts()->count());
    }

    public function test_a_balance_under_the_minimum_cannot_be_requested(): void
    {
        Setting::where('key', 'min_payout_amount')->first()->update(['value' => '50000']);

        $this->deliverAnOrder();
        $this->saveDetails();

        $payout = $this->getJson('/api/v1/seller/earnings')->assertOk()->json('data.payout');

        $this->assertFalse($payout['can_request']);
        $this->assertStringContainsString('reaches', $payout['blocked_reason']);

        $this->postJson('/api/v1/seller/payouts')->assertStatus(422);
    }

    public function test_payouts_are_shut_when_no_method_supports_them(): void
    {
        PaymentMethod::query()->update(['supports_payout' => false]);

        $this->deliverAnOrder();
        Sanctum::actingAs($this->sellerUser);

        $payout = $this->getJson('/api/v1/seller/earnings')->assertOk()->json('data.payout');

        $this->assertFalse($payout['accepting']);
        $this->assertFalse($payout['can_request']);
    }

    public function test_a_bank_transfer_must_name_the_bank(): void
    {
        PaymentMethod::where('code', 'bank_transfer')
            ->update(['is_active' => true, 'supports_payout' => true, 'requires_bank_name' => true]);

        Sanctum::actingAs($this->sellerUser);

        // An account number on its own is not something staff can send to.
        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'bank_transfer',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '0123456789',
        ])->assertStatus(422)->assertJsonValidationErrors('payout_bank_name');

        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'bank_transfer',
            'payout_bank_name' => 'Nabil Bank',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '0123456789',
        ])->assertOk();

        $this->assertSame('Nabil Bank', $this->seller->fresh()->payout_bank_name);
    }

    public function test_a_wallet_is_never_asked_for_a_bank(): void
    {
        Sanctum::actingAs($this->sellerUser);

        $methods = collect($this->getJson('/api/v1/seller/payout-methods')->assertOk()->json('data'))
            ->keyBy('code');

        $this->assertFalse($methods['esewa']['requires_bank_name']);

        // eSewa saves with no bank name at all.
        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'esewa',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '9801234567',
        ])->assertOk();

        $this->assertTrue($this->seller->fresh()->hasPayoutDetails());
    }

    public function test_switching_from_a_bank_to_a_wallet_clears_the_bank(): void
    {
        PaymentMethod::where('code', 'bank_transfer')
            ->update(['is_active' => true, 'supports_payout' => true, 'requires_bank_name' => true]);

        Sanctum::actingAs($this->sellerUser);

        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'bank_transfer',
            'payout_bank_name' => 'Nabil Bank',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '0123456789',
        ])->assertOk();

        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'esewa',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '9801234567',
        ])->assertOk();

        // A wallet has no bank, so the old one must not linger on the record.
        $this->assertNull($this->seller->fresh()->payout_bank_name);
    }

    public function test_a_bank_seller_without_a_bank_name_cannot_be_paid(): void
    {
        PaymentMethod::where('code', 'bank_transfer')
            ->update(['is_active' => true, 'supports_payout' => true, 'requires_bank_name' => true]);

        // Details left half-finished directly on the record, as an older row
        // saved before the bank name was asked for would be.
        $this->seller->update([
            'payout_method' => 'bank_transfer',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '0123456789',
            'payout_bank_name' => null,
        ]);

        $this->deliverAnOrder();
        Sanctum::actingAs($this->sellerUser);

        $payout = $this->getJson('/api/v1/seller/earnings')->assertOk()->json('data.payout');

        $this->assertTrue($payout['needs_bank_name']);
        $this->assertFalse($payout['has_details']);
        $this->assertFalse($payout['can_request']);

        $this->postJson('/api/v1/seller/payouts')->assertStatus(422);
    }

    public function test_the_payout_records_where_the_money_is_going(): void
    {
        PaymentMethod::where('code', 'bank_transfer')
            ->update(['is_active' => true, 'supports_payout' => true, 'requires_bank_name' => true]);

        $this->deliverAnOrder();
        Sanctum::actingAs($this->sellerUser);

        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'bank_transfer',
            'payout_bank_name' => 'Nabil Bank',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '0123456789',
        ])->assertOk();

        $reference = $this->postJson('/api/v1/seller/payouts')->assertCreated()->json('data.reference');
        $payout = Payout::where('reference', $reference)->firstOrFail();

        // Staff can pay this without looking anything else up.
        $this->assertSame('bank_transfer', $payout->method);
        $this->assertSame('Bank Transfer', $payout->method_label);
        $this->assertSame('Nabil Bank', $payout->bank_name);
        $this->assertSame('Karim Uddin', $payout->account_name);
        $this->assertSame('0123456789', $payout->account_number);
        $this->assertStringContainsString('Nabil Bank', $payout->destination);
    }

    public function test_changing_bank_details_does_not_move_a_payout_already_raised(): void
    {
        PaymentMethod::where('code', 'bank_transfer')
            ->update(['is_active' => true, 'supports_payout' => true, 'requires_bank_name' => true]);

        $this->deliverAnOrder();
        Sanctum::actingAs($this->sellerUser);

        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'bank_transfer',
            'payout_bank_name' => 'Nabil Bank',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '0123456789',
        ])->assertOk();

        $reference = $this->postJson('/api/v1/seller/payouts')->assertCreated()->json('data.reference');

        // The seller moves banks after asking to be paid.
        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'esewa',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '9809999999',
        ])->assertOk();

        $payout = Payout::where('reference', $reference)->firstOrFail();

        // The queued payout still says what it was raised against.
        $this->assertSame('Nabil Bank', $payout->bank_name);
        $this->assertSame('0123456789', $payout->account_number);
    }

    /** The whole point of the snapshot: staff can see what to pay against. */
    public function test_the_admin_panel_shows_the_details_the_seller_gave(): void
    {
        PaymentMethod::where('code', 'bank_transfer')
            ->update(['is_active' => true, 'supports_payout' => true, 'requires_bank_name' => true]);

        $this->deliverAnOrder();
        Sanctum::actingAs($this->sellerUser);

        $this->putJson('/api/v1/seller/payout-details', [
            'payout_method' => 'bank_transfer',
            'payout_bank_name' => 'Nabil Bank',
            'payout_account_name' => 'Karim Uddin',
            'payout_account_number' => '0123456789',
        ])->assertOk();

        $reference = $this->postJson('/api/v1/seller/payouts')->assertCreated()->json('data.reference');
        $payout = Payout::where('reference', $reference)->firstOrFail();

        $admin = User::where('role', 'admin')->firstOrFail();

        // Name the guard: acting as the seller through Sanctum above made it
        // the default, and the panel authenticates on the web guard.
        $this->actingAs($admin, 'web')
            ->get('/admin/payouts')
            ->assertOk()
            ->assertSee($reference)
            ->assertSee('0123456789')
            ->assertSee('Nabil Bank');

        $this->actingAs($admin, 'web')
            ->get('/admin/payouts/'.$payout->getKey().'/edit')
            ->assertOk()
            ->assertSee('Nabil Bank')
            ->assertSee('Karim Uddin')
            ->assertSee('0123456789');
    }
}
