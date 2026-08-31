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
            'customer_email' => 'rahim@example.test',
            'area' => 'Ward 4',
            'postal_code' => '44600',
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

    public function test_the_seller_runs_their_own_order_and_payment_closes_it(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);

        Sanctum::actingAs($this->seller->user);

        foreach (['confirmed', 'processing', 'out_for_delivery'] as $status) {
            $this->putJson("/api/v1/seller/orders/{$order->order_number}/status", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        // An unpaid order is not deliverable, however far along it is.
        $this->putJson("/api/v1/seller/orders/{$order->order_number}/status", ['status' => 'delivered'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // ...and once the money is in, nobody has to click anything.
        $this->assertSame('delivered', $this->payInFull($order)->status);
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

        // The escape hatch for disputes stays open alongside the status
        // control staff and the seller now share.
        $order->update(['status' => 'cancelled']);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('published', $goat->fresh()->status);
    }

    public function test_the_admin_status_action_only_offers_the_next_step(): void
    {
        $order = $this->orderFor([$this->sellerGoat('Sequenced Goat')->id]);
        $admin = User::where('role', 'admin')->firstOrFail();

        $this->actingAs($admin);

        // Pending. Out for delivery is three states away, and picking it would
        // leave an order that had never been Confirmed or Prepared -- a buyer
        // timeline describing a sequence that did not happen.
        Livewire::test(ListOrders::class)
            ->callTableAction('updateStatus', $order, data: ['status' => 'out_for_delivery'])
            ->assertHasTableActionErrors(['status']);

        $this->assertSame('pending', $order->fresh()->status);

        // The one step it may take.
        Livewire::test(ListOrders::class)
            ->callTableAction('updateStatus', $order, data: ['status' => 'confirmed'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('confirmed', $order->fresh()->status);

        // And no going back to where it came from.
        Livewire::test(ListOrders::class)
            ->callTableAction('updateStatus', $order->fresh(), data: ['status' => 'pending'])
            ->assertHasTableActionErrors(['status']);

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_cancelling_needs_no_steps_at_all(): void
    {
        // Both orders placed first: orderFor() signs in as the buyer to go
        // through checkout, so acting as the admin has to come after it.
        $early = $this->orderFor([$this->sellerGoat('Early Cancel Goat')->id]);
        $late  = $this->orderFor([$this->sellerGoat('Late Cancel Goat')->id]);
        $late->update(['status' => 'processing']);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        // Straight from Pending.
        Livewire::test(ListOrders::class)
            ->callTableAction('updateStatus', $early, data: ['status' => 'cancelled'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('cancelled', $early->fresh()->status);

        // And from the middle of the run. An order can fall over anywhere, so
        // cancelling is not a step in the sequence and never waits for one.
        Livewire::test(ListOrders::class)
            ->callTableAction('updateStatus', $late->fresh(), data: ['status' => 'cancelled'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('cancelled', $late->fresh()->status);
    }

    public function test_a_finished_order_has_no_status_action_left(): void
    {
        $order = $this->orderFor([$this->sellerGoat('Done Goat')->id]);
        $order->update(['status' => 'cancelled']);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        // Nothing to advance and nothing to cancel, so the control goes away
        // rather than opening onto an empty dropdown.
        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('updateStatus', $order->fresh());
    }

    public function test_the_admin_status_action_is_available_on_every_order(): void
    {
        $sellerOrder = $this->orderFor([$this->sellerGoat('Seller Only Goat')->id]);

        $house = Goat::published()->whereNull('seller_id')->firstOrFail();
        $staffOrder = $this->orderFor([$house->id]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            // Staff run house-stock orders, so the control is there.
            ->assertTableActionVisible('updateStatus', $staffOrder)
            // And they can step in on a seller's order too: the seller is the
            // one who normally moves it, but staff are no longer locked out.
            ->assertTableActionVisible('updateStatus', $sellerOrder)
            // The dispute escape hatch stays open on both.
            ->assertTableActionVisible('cancelOrder', $sellerOrder)
            ->assertTableActionVisible('cancelOrder', $staffOrder);
    }

    public function test_the_admin_status_field_stays_editable_on_a_seller_run_order(): void
    {
        $order = $this->orderFor([$this->sellerGoat('Shared Goat')->id]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertFormFieldEnabled('status');
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
