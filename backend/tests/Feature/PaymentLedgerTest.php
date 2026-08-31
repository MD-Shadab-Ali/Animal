<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "What have I paid this shop?"
 *
 * Every payment was already recorded, but only ever against one order, so the
 * question could not be asked at all without opening each order in turn. The
 * ledger answers it -- and must answer it about the buyer's own money only.
 */
class PaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

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
    }

    private function goat(string $name, float $price): Goat
    {
        return Goat::create([
            'category_id' => Category::first()->id,
            'name' => $name,
            'gender' => 'male',
            'price' => $price,
            'stock' => 3,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);
    }

    /** An order in the given buyer's name, with one goat on it. */
    private function orderFor(User $buyer, string $goatName, float $price): Order
    {
        Sanctum::actingAs($buyer);

        $goat = $this->goat($goatName, $price);
        $this->postJson('/api/v1/cart', ['goat_id' => $goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin',
            'customer_email' => 'rahim@example.test',
            'area' => 'Ward 4',
            'postal_code' => '44600',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'esewa',
            'payment_plan' => 'full',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    private function record(Order $order, string $type, float $amount, string $status = 'confirmed'): Payment
    {
        return Payment::create([
            'reference' => strtoupper($type).'-'.$order->id.'-'.(int) $amount,
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'method' => 'esewa',
            'amount' => $amount,
            'currency' => 'NPR',
            'type' => $type,
            'status' => $status,
            'source' => 'staff',
            'paid_at' => now(),
        ]);
    }

    public function test_the_ledger_lists_every_payment_across_a_buyers_orders(): void
    {
        $first = $this->orderFor($this->buyer, 'Ledger Buck One', 20000);
        $second = $this->orderFor($this->buyer, 'Ledger Buck Two', 30000);

        $this->record($first, 'payment', 20000);
        $this->record($second, 'payment', 30000);

        Sanctum::actingAs($this->buyer);
        $rows = $this->getJson('/api/v1/payments')->assertOk()->json('data');

        $numbers = array_column($rows, 'order_number');

        $this->assertContains($first->order_number, $numbers);
        $this->assertContains($second->order_number, $numbers);

        // A row has to stand on its own: which order, and what it bought.
        $one = collect($rows)->firstWhere('order_number', $first->order_number);
        $this->assertSame('Ledger Buck One', $one['goats']);
        $this->assertSame('Payment', $one['type_label']);
    }

    public function test_a_buyer_never_sees_another_buyers_money(): void
    {
        $mine = $this->orderFor($this->buyer, 'My Buck', 20000);
        $this->record($mine, 'payment', 20000);

        $stranger = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $theirs = $this->orderFor($stranger, 'Their Buck', 45000);
        $this->record($theirs, 'payment', 45000);

        Sanctum::actingAs($this->buyer);
        $numbers = array_column($this->getJson('/api/v1/payments')->assertOk()->json('data'), 'order_number');

        $this->assertContains($mine->order_number, $numbers);
        $this->assertNotContains($theirs->order_number, $numbers);
    }

    public function test_refunds_are_listed_and_carry_their_minus(): void
    {
        $order = $this->orderFor($this->buyer, 'Refunded Buck', 20000);
        $this->record($order, 'payment', 20000);
        $this->record($order, 'refund', 5000);

        Sanctum::actingAs($this->buyer);
        $response = $this->getJson('/api/v1/payments')->assertOk();

        $refund = collect($response->json('data'))->firstWhere('type', 'refund');

        $this->assertNotNull($refund, 'A refund belongs in a ledger; leaving it out stops the column adding up.');
        $this->assertEquals(-5000, $refund['signed_amount']);
        $this->assertSame('Refunded', $refund['status_label']);

        $this->assertEquals(20000, $response->json('summary.paid'));
        $this->assertEquals(5000, $response->json('summary.refunded'));
    }

    public function test_money_nobody_has_checked_yet_is_not_counted_as_paid(): void
    {
        $order = $this->orderFor($this->buyer, 'Claimed Buck', 20000);
        $this->record($order, 'payment', 20000, 'pending');

        Sanctum::actingAs($this->buyer);
        $response = $this->getJson('/api/v1/payments')->assertOk();

        // Listed, because the buyer sent it and wants to see that we have it.
        $this->assertCount(1, $response->json('data'));
        // Not counted, because staff have not agreed it landed.
        $this->assertEquals(0, $response->json('summary.paid'));
        $this->assertSame('Awaiting check', $response->json('data.0.status_label'));
    }

    public function test_the_ledger_is_closed_to_strangers(): void
    {
        $this->getJson('/api/v1/payments')->assertUnauthorized();
    }
}
