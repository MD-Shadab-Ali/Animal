<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
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
use Livewire\Livewire;
use Tests\TestCase;

class OrderOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->seller = Seller::where('slug', 'karim-livestock')->firstOrFail();
    }

    private function sellerGoat(string $name = 'Seller Goat', ?Seller $owner = null): Goat
    {
        return Goat::create([
            'category_id' => Category::first()->id,
            'seller_id'   => ($owner ?? $this->seller)->id,
            'name' => $name, 'gender' => 'male', 'price' => 30000,
            'stock' => 1, 'track_stock' => true,
            'status' => 'published', 'approval_status' => 'approved',
        ]);
    }

    private function makeSeller(string $email): Seller
    {
        $user = User::create([
            'name' => 'Seller '.$email, 'email' => $email,
            'phone' => '+880 1700-000000', 'password' => 'password',
            'role' => 'customer', 'is_active' => true,
        ]);

        return Seller::create([
            'user_id' => $user->id, 'farm_name' => 'Farm '.$email,
            'contact_phone' => '+880 1700-000000', 'city' => 'Sylhet',
            'status' => 'approved', 'approved_at' => now(),
        ]);
    }

    private function orderFor(array $goatIds): Order
    {
        $buyer = User::where('role', 'customer')
            ->where('id', '!=', $this->seller->user_id)
            ->firstOrFail();

        Sanctum::actingAs($buyer);
        $this->deleteJson('/api/v1/cart');

        foreach ($goatIds as $id) {
            $this->postJson('/api/v1/cart', ['goat_id' => $id])->assertCreated();
        }

        $number = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Rahim Uddin', 'customer_phone' => '+880 1811-111111',
            'address_line' => 'House 12', 'city' => 'Dhaka',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    public function test_an_order_of_only_one_sellers_goats_belongs_to_that_seller(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);

        $this->assertTrue($order->isSellerManaged());
        $this->assertSame($this->seller->id, $order->soleSellerId());
        $this->assertFalse($order->isStaffManaged());
    }

    public function test_an_order_containing_house_stock_stays_with_staff(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();

        $order = $this->orderFor([$this->sellerGoat()->id, $house->id]);

        $this->assertFalse($order->isSellerManaged());
        $this->assertTrue($order->isStaffManaged());
        $this->assertNull($order->soleSellerId());
    }

    public function test_an_order_spanning_two_sellers_stays_with_staff(): void
    {
        $other = $this->makeSeller('other-owner@example.test');

        $order = $this->orderFor([
            $this->sellerGoat()->id,
            $this->sellerGoat('Other Seller Goat', $other)->id,
        ]);

        $this->assertFalse($order->isSellerManaged());
        $this->assertNull($order->soleSellerId());
    }

    public function test_the_seller_can_run_their_own_order_to_delivered(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);

        Sanctum::actingAs($this->seller->user);

        foreach (['confirmed', 'processing', 'out_for_delivery', 'delivered'] as $status) {
            $this->putJson("/api/v1/seller/orders/{$order->order_number}/status", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_a_seller_cannot_run_an_order_that_includes_house_stock(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $order = $this->orderFor([$this->sellerGoat()->id, $house->id]);

        Sanctum::actingAs($this->seller->user);

        $this->putJson("/api/v1/seller/orders/{$order->order_number}/status", ['status' => 'confirmed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_a_seller_cannot_rewind_or_cancel_an_order(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);

        Sanctum::actingAs($this->seller->user);

        $this->putJson("/api/v1/seller/orders/{$order->order_number}/status", ['status' => 'processing'])->assertOk();

        $this->putJson("/api/v1/seller/orders/{$order->order_number}/status", ['status' => 'confirmed'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->putJson("/api/v1/seller/orders/{$order->order_number}/status", ['status' => 'cancelled'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_a_seller_cannot_run_someone_elses_order(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);

        $intruder = $this->makeSeller('intruder@example.test');
        Sanctum::actingAs($intruder->user);

        $this->putJson("/api/v1/seller/orders/{$order->order_number}/status", ['status' => 'confirmed'])
            ->assertStatus(422)->assertJsonValidationErrors('order');

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_the_seller_order_list_flags_which_orders_they_run(): void
    {
        $this->orderFor([$this->sellerGoat()->id]);

        Sanctum::actingAs($this->seller->user);

        $this->getJson('/api/v1/seller/orders')
            ->assertOk()
            ->assertJsonPath('data.0.you_manage', true)
            ->assertJsonPath('data.0.next_status.0.value', 'confirmed');
    }

    public function test_staff_can_still_cancel_a_seller_run_order(): void
    {
        $goat = $this->sellerGoat();
        $order = $this->orderFor([$goat->id]);

        // The escape hatch for disputes stays open even though staff cannot
        // move the order forward.
        $order->update(['status' => 'cancelled']);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('published', $goat->fresh()->status);
    }

    public function test_the_admin_status_action_is_hidden_on_a_seller_run_order(): void
    {
        $sellerOrder = $this->orderFor([$this->sellerGoat('Seller Only Goat')->id]);

        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $staffOrder = $this->orderFor([$house->id]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            // Staff run house-stock orders, so the control is there.
            ->assertTableActionVisible('updateStatus', $staffOrder)
            // The seller runs theirs, so staff must not be able to move it.
            ->assertTableActionHidden('updateStatus', $sellerOrder)
            // But the dispute escape hatch stays open on both.
            ->assertTableActionVisible('cancelOrder', $sellerOrder)
            ->assertTableActionVisible('cancelOrder', $staffOrder);
    }

    public function test_the_admin_status_field_is_disabled_on_a_seller_run_order(): void
    {
        $order = $this->orderFor([$this->sellerGoat('Locked Goat')->id]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertFormFieldDisabled('status');
    }

    public function test_the_admin_status_field_stays_editable_on_a_house_order(): void
    {
        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $order = $this->orderFor([$house->id]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertFormFieldEnabled('status');
    }
}
