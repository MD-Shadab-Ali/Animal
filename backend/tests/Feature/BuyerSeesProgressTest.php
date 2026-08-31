<?php

namespace Tests\Feature;

use Filament\Actions\Testing\TestAction;
use App\Filament\Resources\Orders\Pages\ListOrders;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
            'customer_email' => 'rahim@example.test',
            'area' => 'Ward 4',
            'postal_code' => '44600',
            'address_line' => 'Baghbazar', 'city' => 'Kathmandu',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method' => 'cod',
        ])->assertCreated()->json('data.order_number');

        return Order::where('order_number', $number)->firstOrFail();
    }

    public function test_a_note_and_photo_left_by_staff_reach_the_buyer(): void
    {
        $order = $this->orderFor([$this->sellerGoat('Photographed Goat')->id]);

        Storage::fake('public');
        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        // One step at a time: the admin panel only ever offers the next status,
        // so reaching Preparing means passing through Confirmed first.
        Livewire::test(ListOrders::class)
            ->callTableAction('updateStatus', $order, data: ['status' => 'confirmed'])
            ->assertHasNoTableActionErrors();

        Livewire::test(ListOrders::class)
            ->callTableAction('updateStatus', $order->fresh(), data: [
                'status' => 'processing',
                'note'   => 'Your goat is being prepared. Photo attached.',
                'photo'  => UploadedFile::fake()->image('goat.jpg'),
            ])
            ->assertHasNoTableActionErrors();

        $entry = $order->fresh()->statusHistories()
            ->where('to_status', 'processing')->latest('id')->firstOrFail();

        $this->assertNotNull($entry->photo);
        Storage::disk('public')->assertExists($entry->photo);

        // The whole point: what staff wrote and photographed has to come back
        // out on the buyer's own order, not sit in an admin-only column.
        Sanctum::actingAs($this->buyer);

        $history = collect($this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->json('data.history'))
            ->firstWhere('status', 'processing');

        $this->assertSame('Your goat is being prepared. Photo attached.', $history['note']);
        $this->assertStringContainsString($entry->photo, $history['photo']);
    }

    public function test_the_photo_field_belongs_to_preparing_alone(): void
    {
        $order = $this->orderFor([$this->sellerGoat('Scoped Goat')->id]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        $action = TestAction::make('updateStatus')->table($order);

        $form = Livewire::test(ListOrders::class)->mountAction($action);

        // Confirmed is about where the order is, not what the animal looks
        // like, so there is nothing to photograph yet.
        $form->fillForm(['status' => 'confirmed'])
            ->assertFormFieldHidden('photo');

        // Preparing is the step the buyer cannot see for themselves.
        $form->fillForm(['status' => 'processing'])
            ->assertFormFieldVisible('photo');

        $form->fillForm(['status' => 'out_for_delivery'])
            ->assertFormFieldHidden('photo');
    }

    public function test_a_photo_does_not_ride_along_on_another_step(): void
    {
        $order = $this->orderFor([$this->sellerGoat('Switched Goat')->id]);

        Storage::fake('public');
        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        // A photo picked while Preparing was selected, then the status changed
        // away before submitting. The field is hidden by then, but the value
        // can still be sitting in the form state.
        Livewire::test(ListOrders::class)
            ->callTableAction('updateStatus', $order, data: [
                'status' => 'confirmed',
                'photo'  => UploadedFile::fake()->image('stray.jpg'),
            ])
            ->assertHasNoTableActionErrors();

        $entry = $order->fresh()->statusHistories()
            ->where('to_status', 'confirmed')->latest('id')->firstOrFail();

        $this->assertNull($entry->photo);
    }

    public function test_a_status_change_with_nothing_attached_leaves_no_photo(): void
    {
        $order = $this->orderFor([$this->sellerGoat('Quiet Goat')->id]);

        $this->actingAs(User::where('role', 'admin')->firstOrFail());

        Livewire::test(ListOrders::class)
            ->callTableAction('updateStatus', $order, data: ['status' => 'confirmed'])
            ->assertHasNoTableActionErrors();

        $entry = $order->fresh()->statusHistories()
            ->where('to_status', 'confirmed')->latest('id')->firstOrFail();

        // The buyer's page hides rows with nothing on them, so a blank note and
        // photo have to stay blank rather than becoming empty strings.
        $this->assertNull($entry->photo);
        $this->assertNull($entry->note);
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

    /**
     * What a line says has to agree with the step the order is on.
     *
     * `confirmed` used to drag every line to "preparing", so the buyer saw an
     * amber "Preparing the animal" under a timeline still reading Confirmed —
     * claiming work nobody had started.
     */
    public function test_a_line_never_runs_ahead_of_the_order_timeline(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);

        // Placed: nothing has happened to the animal.
        $this->assertSame('pending', $order->items->first()->fulfilment_status);

        $order->update(['status' => 'confirmed']);

        $this->assertSame(
            'pending',
            $order->fresh()->items->first()->fulfilment_status,
            'Confirming an order does not mean anyone has started preparing'
        );

        $line = $this->buyerSees($order)['items'][0];

        $this->assertSame('pending', $line['fulfilment']['status']);
        $this->assertSame('Not started', $line['fulfilment']['label']);

        // The order reaching "Preparing" is what makes the line say so.
        $order->fresh()->update(['status' => 'processing']);

        $line = $this->buyerSees($order)['items'][0];

        $this->assertSame('preparing', $line['fulfilment']['status']);
        $this->assertSame('Preparing the animal', $line['fulfilment']['label']);
    }

    /**
     * A supplier's job ends at the courier; the buyer's does not.
     *
     * The stored line state stops at `handed_over` because that is the last
     * thing the farm actually does. Telling the buyer their goat is with the
     * courier once it is standing in their yard is simply out of date.
     */
    public function test_a_delivered_order_stops_saying_the_goat_is_with_the_courier(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);

        $order->update(['status' => 'out_for_delivery']);

        $line = $this->buyerSees($order)['items'][0];

        $this->assertSame('handed_over', $line['fulfilment']['status']);
        $this->assertSame('Handed to the courier', $line['fulfilment']['label']);

        // Delivery needs the money in, as everywhere else.
        $this->payInFull($order->fresh());

        $this->assertSame('delivered', $order->fresh()->status);

        $line = $this->buyerSees($order)['items'][0];

        $this->assertSame('delivered', $line['fulfilment']['status']);
        $this->assertSame('Delivered', $line['fulfilment']['label']);

        // The supplier's own record is untouched: handing over is what they did.
        $this->assertSame('handed_over', $order->fresh()->items->first()->fulfilment_status);
    }

    /** A cancelled line stays cancelled, whatever the order says. */
    public function test_a_cancelled_line_is_not_relabelled_as_delivered(): void
    {
        $order = $this->orderFor([$this->sellerGoat()->id]);

        $order->items()->update(['fulfilment_status' => 'cancelled']);

        $order->update(['status' => 'out_for_delivery']);
        $this->payInFull($order->fresh());

        $line = $this->buyerSees($order)['items'][0];

        $this->assertSame('cancelled', $line['fulfilment']['status']);
    }

}
