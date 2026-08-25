<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Models\User;
use App\Services\SellerFulfilmentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuyerSeesProgressTest extends TestCase
{
    use RefreshDatabase;

    private Seller $seller;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->seller = Seller::where('slug', 'karim-livestock')->firstOrFail();
        $this->buyer = User::where('role', 'customer')
            ->where('id', '!=', $this->seller->user_id)->firstOrFail();
    }

    private function sellerGoat(string $name = 'Progress Goat'): Goat
    {
        return Goat::create([
            'category_id' => Category::first()->id, 'seller_id' => $this->seller->id,
            'name' => $name, 'gender' => 'male', 'price' => 30000,
            'stock' => 1, 'track_stock' => true,
            'status' => 'published', 'approval_status' => 'approved',
        ]);
    }

    private function orderFor(array $ids): Order
    {
        Sanctum::actingAs($this->buyer);
        $this->deleteJson('/api/v1/cart');

        foreach ($ids as $id) {
            $this->postJson('/api/v1/cart', ['goat_id' => $id])->assertCreated();
        }

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim', 'customer_phone' => '+977 9801-111111',
            'address_line' => 'Baghbazar', 'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    private function buyerSees(Order $order): array
    {
        Sanctum::actingAs($this->buyer);

        return $this->getJson("/api/v1/orders/{$order->order_number}")->assertOk()->json('data');
    }

    public function test_buyer_sees_a_seller_run_order_move(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);

        Sanctum::actingAs($this->seller->user);
        $this->putJson("/api/v1/seller/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertOk();

        $this->assertSame('confirmed', $this->buyerSees($order)['status']);
    }

    public function test_buyer_sees_per_goat_progress_and_who_supplied_it(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);
        $line = $order->items()->firstOrFail();

        app(SellerFulfilmentService::class)->advance($this->seller, $line, 'ready');

        $item = $this->buyerSees($order)['items'][0];

        $this->assertSame('ready', $item['fulfilment']['status']);
        $this->assertSame('Ready for collection', $item['fulfilment']['label']);
        $this->assertSame('Karim Livestock', $item['supplied_by']);
    }

    public function test_house_stock_shows_the_shop_as_the_supplier(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $order = $this->orderFor([$house->id]);

        $item = $this->buyerSees($order)['items'][0];

        $this->assertSame('Goat Haven', $item['supplied_by']);
        $this->assertSame('pending', $item['fulfilment']['status']);
    }

    /** The reported bug: a seller moved their line and the buyer saw nothing. */
    public function test_a_mixed_order_advances_once_every_line_has_moved(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $order = $this->orderFor([$this->sellerGoat()->id, $house->id]);

        $sellerLine = $order->items()->whereNotNull('seller_id')->firstOrFail();
        $houseLine = $order->items()->whereNull('seller_id')->firstOrFail();

        // Seller moves first. The house line is still untouched, so the order
        // must not run ahead of the slowest supplier.
        app(SellerFulfilmentService::class)->advance($this->seller, $sellerLine, 'preparing');
        $this->assertSame('pending', $this->buyerSees($order)['status']);

        // Staff move the house line; now every line has started.
        $houseLine->update(['fulfilment_status' => 'preparing']);
        app(SellerFulfilmentService::class)->syncOrderStatusFromLines($order);

        $this->assertSame('processing', $this->buyerSees($order)['status']);
    }

    public function test_a_mixed_order_reaches_out_for_delivery_when_all_lines_are_handed_over(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $order = $this->orderFor([$this->sellerGoat()->id, $house->id]);

        OrderItem::where('order_id', $order->id)->update(['fulfilment_status' => 'handed_over']);
        app(SellerFulfilmentService::class)->syncOrderStatusFromLines($order);

        $this->assertSame('out_for_delivery', $this->buyerSees($order)['status']);
    }

    public function test_moving_the_order_drags_lagging_lines_forward(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $order = $this->orderFor([$this->sellerGoat()->id, $house->id]);

        // Staff push the whole order out; no line should be left saying "not started".
        $order->update(['status' => 'out_for_delivery']);

        foreach ($this->buyerSees($order)['items'] as $item) {
            $this->assertSame('handed_over', $item['fulfilment']['status']);
        }
    }

    public function test_the_roll_up_never_rewinds_a_manual_staff_decision(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $order = $this->orderFor([$this->sellerGoat()->id, $house->id]);

        $order->update(['status' => 'out_for_delivery']);

        // A line report that maps to an earlier stage must not pull it back.
        app(SellerFulfilmentService::class)->syncOrderStatusFromLines($order);

        $this->assertSame('out_for_delivery', $order->fresh()->status);
    }
}
