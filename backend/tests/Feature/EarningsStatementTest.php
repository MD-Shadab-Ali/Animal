<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EarningsStatementTest extends TestCase
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

        $this->zone = DeliveryZone::active()->orderByDesc('charge')->firstOrFail();
        $this->zone->update(['free_above' => null]);
    }

    private function goat(string $name, float $price = 25000): Goat
    {
        return Goat::create([
            'category_id' => Category::first()->id, 'seller_id' => $this->seller->id,
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
            'delivery_zone_id' => $this->zone->id, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    private function statement(): array
    {
        Sanctum::actingAs($this->seller->user);

        return $this->getJson('/api/v1/seller/earnings')->assertOk()->json('data');
    }

    public function test_the_statement_shows_delivery_beside_commission(): void
    {
        $order = $this->order([$this->goat('Statement Goat')->id]);
        $delivery = (float) $order->delivery_earning;

        $line = $this->statement()['lines'][0];

        $this->assertEquals(25000, $line['gross']);
        $this->assertEquals(2500, $line['commission']);
        $this->assertEquals($delivery, $line['delivery']);
        $this->assertEquals(22500 + $delivery, $line['earning']);
    }

    /** Delivery is per order, so two goats on one order must not claim it twice. */
    public function test_delivery_is_counted_once_when_an_order_has_two_goats(): void
    {
        $order = $this->order([
            $this->goat('Twin Goat A')->id,
            $this->goat('Twin Goat B')->id,
        ]);

        $delivery = (float) $order->delivery_earning;
        $lines = $this->statement()['lines'];

        $this->assertCount(2, $lines);
        $this->assertEquals(
            $delivery,
            array_sum(array_column($lines, 'delivery')),
            'The delivery charge must appear once across the order, not on every goat'
        );

        // And the rows still add up to what the seller is actually owed.
        $this->assertEquals(
            $this->seller->fresh()->pending_earnings,
            round(array_sum(array_column($lines, 'earning')), 2)
        );
    }

    public function test_delivery_shows_as_zero_when_the_platform_delivers(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $this->order([$this->goat('Mixed Statement Goat')->id, $house->id]);

        $line = $this->statement()['lines'][0];

        $this->assertEquals(0, $line['delivery']);
        $this->assertEquals(22500, $line['earning']);
    }

    public function test_the_statement_rows_reconcile_with_the_summary(): void
    {
        $this->order([$this->goat('Reconcile Goat')->id]);

        $data = $this->statement();

        $this->assertEquals(
            $data['summary']['pending'],
            round(array_sum(array_column($data['lines'], 'earning')), 2)
        );
    }
}
