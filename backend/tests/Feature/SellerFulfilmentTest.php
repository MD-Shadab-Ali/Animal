<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\SellerReadyForCollectionNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private Seller $seller;
    private Goat $goat;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->seller = Seller::where('slug', 'karim-livestock')->firstOrFail();

        $this->goat = Goat::create([
            'category_id' => Category::first()->id,
            'seller_id'   => $this->seller->id,
            'name' => 'Fulfilment Test Goat', 'gender' => 'male',
            'price' => 30000, 'stock' => 1, 'track_stock' => true,
            'status' => 'published', 'approval_status' => 'approved',
        ]);

        $buyer = User::where('role', 'customer')->where('id', '!=', $this->seller->user_id)->firstOrFail();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/cart', ['goat_id' => $this->goat->id])->assertCreated();

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin', 'customer_phone' => '+880 1811-111111',
            'address_line' => 'House 12', 'city' => 'Dhaka',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        $this->order = Order::where('order_number', $number)->firstOrFail();
    }

    private function line(): OrderItem
    {
        return $this->order->items()->where('seller_id', $this->seller->id)->firstOrFail();
    }

    private function actAsSeller(): void
    {
        Sanctum::actingAs($this->seller->user);
    }

    public function test_a_new_sale_starts_as_not_started(): void
    {
        $this->assertSame('pending', $this->line()->fulfilment_status);
    }

    public function test_a_seller_can_move_their_own_line_forward(): void
    {
        $this->actAsSeller();
        $item = $this->line();

        foreach (['preparing', 'ready', 'handed_over'] as $status) {
            $this->putJson("/api/v1/seller/order-items/{$item->id}/status", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.fulfilment.status', $status);
        }

        $this->assertSame('handed_over', $item->fresh()->fulfilment_status);
        $this->assertNotNull($item->fresh()->fulfilment_updated_at);
    }

    public function test_a_line_cannot_be_rewound(): void
    {
        $this->actAsSeller();
        $item = $this->line();

        $this->putJson("/api/v1/seller/order-items/{$item->id}/status", ['status' => 'ready'])->assertOk();

        $this->putJson("/api/v1/seller/order-items/{$item->id}/status", ['status' => 'preparing'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('ready', $item->fresh()->fulfilment_status);
    }

    public function test_a_seller_cannot_cancel_a_line_themselves(): void
    {
        $this->actAsSeller();

        $this->putJson("/api/v1/seller/order-items/{$this->line()->id}/status", ['status' => 'cancelled'])
            ->assertStatus(422);
    }

    public function test_a_seller_cannot_touch_another_sellers_line(): void
    {
        $otherUser = User::create([
            'name' => 'Other Seller', 'email' => 'other-seller@example.test',
            'phone' => '+880 1700-555555', 'password' => 'password',
            'role' => 'customer', 'is_active' => true,
        ]);

        Seller::create([
            'user_id' => $otherUser->id, 'farm_name' => 'Other Farm',
            'contact_phone' => '+880 1700-555555', 'city' => 'Sylhet',
            'status' => 'approved', 'approved_at' => now(),
        ]);

        Sanctum::actingAs($otherUser);

        $this->putJson("/api/v1/seller/order-items/{$this->line()->id}/status", ['status' => 'ready'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');

        $this->assertSame('pending', $this->line()->fulfilment_status);
    }

    public function test_marking_ready_tells_staff_to_collect(): void
    {
        $this->actAsSeller();

        $this->putJson("/api/v1/seller/order-items/{$this->line()->id}/status", [
            'status' => 'ready',
            'note'   => 'Collect before noon please.',
        ])->assertOk();

        $admin = User::where('role', 'admin')->firstOrFail();

        Notification::assertSentTo($admin, SellerReadyForCollectionNotification::class,
            fn (SellerReadyForCollectionNotification $n) => $n->item->is($this->line()));

        $this->assertSame('Collect before noon please.', $this->line()->fulfilment_note);
    }

    public function test_cancelling_the_order_cancels_the_sellers_line(): void
    {
        $this->order->update(['status' => 'cancelled']);

        $this->assertSame('cancelled', $this->line()->fulfilment_status);

        $this->actAsSeller();

        $this->putJson("/api/v1/seller/order-items/{$this->line()->id}/status", ['status' => 'ready'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_the_orders_list_offers_the_next_steps(): void
    {
        $this->actAsSeller();

        $this->getJson('/api/v1/seller/orders')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.fulfilment.status', 'pending')
            ->assertJsonPath('data.0.items.0.fulfilment.next.0.value', 'preparing');
    }
}
