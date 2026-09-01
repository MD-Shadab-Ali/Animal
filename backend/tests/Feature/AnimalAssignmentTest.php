<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Category;
use App\Models\Goat;
use App\Models\GoatWeight;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Choosing which goat actually walks out of the pen.
 *
 * The buyer picked a weight; staff pick the animal. The nearest one is chosen
 * for them at the Preparing step, they may take a heavier or lighter one
 * instead, and whichever they take stops being available to anybody else.
 *
 * What none of it does is move money: the price follows the delivery weigh-in,
 * which is the one place that re-prices an order.
 */
class AnimalAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();

        $this->admin = User::where('role', 'admin')->firstOrFail();
    }

    /** A confirmed order for $orderedKg, on a listing holding $pool. */
    private function order(array $pool, float $orderedKg): Order
    {
        $goat = Goat::create([
            'category_id' => Category::first()->id,
            'name' => 'Pen Buck',
            'gender' => 'male',
            'price' => 5000,
            'weight_kg' => 50,
            'min_weight_kg' => 20,
            'max_weight_kg' => 60,
            'weight_step_kg' => 1,
            'stock' => 9,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        foreach ($pool as $kg) {
            GoatWeight::create(['goat_id' => $goat->id, 'weight_kg' => $kg, 'tag' => 'PB-'.$kg]);
        }

        $order = Order::create([
            'user_id' => User::where('role', 'customer')->firstOrFail()->id,
            'order_number' => 'GH-TEST-'.strtoupper(uniqid()),
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'payment_method' => 'esewa',
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'subtotal' => 5000,
            'total' => 5000,
            'paid_amount' => 5000,
            'currency' => 'NPR',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'goat_id' => $goat->id,
            'goat_name' => $goat->name,
            'weight_kg' => $orderedKg,
            'unit_price' => $goat->priceForWeight($orderedKg),
            'quantity' => 1,
            'line_total' => $goat->priceForWeight($orderedKg),
        ]);

        return $order->fresh();
    }

    private function moveToProcessing(Order $order, array $animals): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListOrders::class)
            ->callTableAction('updateStatus', $order, data: [
                'status' => 'processing',
                'animals' => $animals,
            ]);
    }

    /** Ordered 45; the pen holds 44 and 47, so 44 is the nearer. */
    public function test_the_nearest_animal_is_the_one_suggested(): void
    {
        $order = $this->order([40, 44, 47, 52], 45);
        $item = $order->items->first();

        $this->assertSame(44.0, (float) $item->goat->nearestWeight(45)->weight_kg);
    }

    public function test_assigning_ties_the_line_to_that_animal_and_sells_it(): void
    {
        $order = $this->order([40, 44, 47], 45);
        $item = $order->items->first();
        $chosen = $item->goat->weights->firstWhere('weight_kg', 44);

        $this->moveToProcessing($order, [
            ['item_id' => $item->id, 'animal_id' => $chosen->id],
        ]);

        $item = $item->fresh();

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame($chosen->id, $item->goat_weight_id);
        $this->assertSame('sold', $chosen->fresh()->status);
        $this->assertNotNull($chosen->fresh()->sold_at);
    }

    /** Staff may overrule the suggestion and send a heavier animal. */
    public function test_staff_can_take_a_heavier_animal_instead(): void
    {
        $order = $this->order([40, 44, 47], 45);
        $item = $order->items->first();
        $heavier = $item->goat->weights->firstWhere('weight_kg', 47);

        $this->moveToProcessing($order, [
            ['item_id' => $item->id, 'animal_id' => $heavier->id],
        ]);

        $this->assertSame($heavier->id, $item->fresh()->goat_weight_id);
        $this->assertSame('sold', $heavier->fresh()->status);
        // The one the system would have picked is untouched.
        $this->assertSame('available', $item->goat->weights->firstWhere('weight_kg', 44)->fresh()->status);
    }

    /**
     * Assignment is not a price change.
     *
     * A heavier animal costs more, but that is settled on the scale at
     * delivery. Re-pricing here as well would mean two places moving the same
     * number.
     */
    public function test_taking_a_heavier_animal_does_not_change_what_is_owed(): void
    {
        $order = $this->order([40, 44, 47], 45);
        $item = $order->items->first();
        $before = (float) $order->total;

        $this->moveToProcessing($order, [
            ['item_id' => $item->id, 'animal_id' => $item->goat->weights->firstWhere('weight_kg', 47)->id],
        ]);

        $item = $item->fresh();

        $this->assertSame($before, (float) $order->fresh()->total);
        $this->assertSame(45.0, (float) $item->weight_kg, 'what the buyer paid for is unchanged');
        $this->assertNull($item->delivered_weight_kg, 'the scale has not spoken yet');
    }

    /** Changing your mind must not strand the first goat as sold to nobody. */
    public function test_reassigning_puts_the_first_animal_back(): void
    {
        $order = $this->order([40, 44, 47], 45);
        $item = $order->items->first();
        $first = $item->goat->weights->firstWhere('weight_kg', 44);
        $second = $item->goat->weights->firstWhere('weight_kg', 47);

        $item->assignAnimal($first);
        $this->assertSame('sold', $first->fresh()->status);

        $item->fresh()->assignAnimal($second);

        $this->assertSame('available', $first->fresh()->status);
        $this->assertNull($first->fresh()->sold_at);
        $this->assertSame('sold', $second->fresh()->status);
        $this->assertSame($second->id, $item->fresh()->goat_weight_id);
    }

    /** A listing keeping no animals is prepared exactly as it always was. */
    public function test_an_order_without_a_pool_still_moves_to_processing(): void
    {
        $order = $this->order([], 45);

        $this->moveToProcessing($order, []);

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertNull($order->items->first()->goat_weight_id);
    }

    /** A sold animal leaves the pen, so nobody else can be promised it. */
    public function test_an_assigned_animal_is_no_longer_offered(): void
    {
        $order = $this->order([44, 47], 45);
        $item = $order->items->first();
        $chosen = $item->goat->weights->firstWhere('weight_kg', 44);

        $this->moveToProcessing($order, [
            ['item_id' => $item->id, 'animal_id' => $chosen->id],
        ]);

        $goat = $item->goat->fresh();

        $this->assertSame([47.0], $goat->availableWeights()
            ->map(fn (GoatWeight $w) => (float) $w->weight_kg)->all());
        $this->assertSame(47.0, (float) $goat->nearestWeight(45)->weight_kg);
    }

    /**
     * A cancelled order gives its animal back.
     *
     * Marking one sold is what stops two buyers being promised the same goat.
     * If the order falls over and that is not undone, the animal is sold to
     * nobody: gone from every buyer's selector and from the picker staff use
     * for the next order, with no way back except editing it by hand.
     */
    public function test_cancelling_an_order_puts_the_animal_back_in_the_pen(): void
    {
        $order = $this->order([44, 47], 45);
        $item = $order->items->first();
        $chosen = $item->goat->weights->firstWhere('weight_kg', 44);

        $this->moveToProcessing($order, [
            ['item_id' => $item->id, 'animal_id' => $chosen->id],
        ]);

        $this->assertSame('sold', $chosen->fresh()->status);

        $order->fresh()->update(['status' => 'cancelled']);

        $this->assertSame('available', $chosen->fresh()->status);
        $this->assertNull($chosen->fresh()->sold_at);
        // The line stops claiming an animal it is not getting.
        $this->assertNull($item->fresh()->goat_weight_id);
        // And it is offered to the next buyer again.
        $this->assertSame(44.0, (float) $item->goat->fresh()->nearestWeight(45)->weight_kg);
    }

    /**
     * The animal's own photograph is used for the Preparing step.
     *
     * Staff were being asked to photograph an animal whose picture is already
     * on its record -- the same job twice, and two copies to keep in step.
     */
    public function test_the_preparing_photo_comes_from_the_animal(): void
    {
        $order = $this->order([44, 47], 45);
        $item = $order->items->first();
        $chosen = $item->goat->weights->firstWhere('weight_kg', 44);
        $chosen->forceFill(['image' => 'goats/animals/pb-44.jpg'])->save();

        $this->moveToProcessing($order, [
            ['item_id' => $item->id, 'animal_id' => $chosen->id],
        ]);

        $step = $order->fresh()->statusHistories()->where('to_status', 'processing')->latest('id')->first();

        $this->assertSame('goats/animals/pb-44.jpg', $step->photo);
    }

    /**
     * What the preview draws, given what is picked in the form.
     *
     * Reached by reflection because the modal body is rendered in the browser,
     * not in the page Livewire hands back -- so the markup itself is the only
     * place this can be checked on the server.
     */
    private function preview(array $animals): string
    {
        $method = new ReflectionMethod(OrdersTable::class, 'animalPhotoPreview');

        return (string) $method->invoke(null, $animals);
    }

    /**
     * The photograph is on screen, not just promised in a sentence.
     *
     * An empty dropzone under the words "the photo already on the animal is
     * used automatically" asks staff to take the form's word for it. The point
     * of showing it is that they can check it against the goat in front of them.
     */
    public function test_the_form_shows_the_photo_it_is_going_to_use(): void
    {
        $order = $this->order([44, 47], 45);
        $item = $order->items->first();
        $chosen = $item->goat->weights->firstWhere('weight_kg', 44);
        $chosen->forceFill(['image' => 'goats/animals/pb-44.jpg'])->save();

        $html = $this->preview([['item_id' => $item->id, 'animal_id' => $chosen->id]]);

        $this->assertStringContainsString('pb-44.jpg', $html);
        $this->assertStringContainsString('PB-44', $html);
        $this->assertStringContainsString('<img', $html);
    }

    /**
     * The preview and the saved photo are the same photo.
     *
     * These are two separate walks over the order -- the form's picks here, the
     * saved lines there. If they ever disagree the preview shows staff one
     * animal and sends the buyer another, which is worse than showing nothing.
     */
    public function test_the_preview_matches_the_photo_that_gets_saved(): void
    {
        // First line has no photograph, so the answer is the second line's --
        // the case where "first animal" and "first photo" part company.
        $order = $this->order([44, 47], 45);
        $item = $order->items->first();
        $bare = $item->goat->weights->firstWhere('weight_kg', 44);
        $photographed = $item->goat->weights->firstWhere('weight_kg', 47);
        $photographed->forceFill(['image' => 'goats/animals/pb-47.jpg'])->save();

        $second = OrderItem::create([
            'order_id' => $order->id,
            'goat_id' => $item->goat_id,
            'goat_name' => $item->goat_name,
            'weight_kg' => 47,
            'unit_price' => $item->unit_price,
            'quantity' => 1,
            'line_total' => $item->line_total,
        ]);

        $picks = [
            ['item_id' => $item->id, 'animal_id' => $bare->id],
            ['item_id' => $second->id, 'animal_id' => $photographed->id],
        ];

        $this->assertStringContainsString('pb-47.jpg', $this->preview($picks));

        $this->moveToProcessing($order->fresh(), $picks);

        $step = $order->fresh()->statusHistories()->where('to_status', 'processing')->latest('id')->first();

        $this->assertSame('goats/animals/pb-47.jpg', $step->photo);
    }

    /** An animal nobody photographed says so, rather than showing an empty box. */
    public function test_an_animal_without_a_photo_says_so(): void
    {
        $order = $this->order([44], 45);
        $item = $order->items->first();
        $chosen = $item->goat->weights->first();

        $html = $this->preview([['item_id' => $item->id, 'animal_id' => $chosen->id]]);

        $this->assertStringContainsString('has no photograph on file', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    /** Nothing picked yet is a prompt, not an error. */
    public function test_no_animal_picked_yet_prompts_rather_than_breaking(): void
    {
        $this->order([44], 45);

        $this->assertStringContainsString(
            'Choose the animal below',
            $this->preview([['item_id' => 1, 'animal_id' => null]])
        );
    }

    /** A photograph uploaded by hand still wins over the stored one. */
    public function test_an_uploaded_photo_overrides_the_animal_photo(): void
    {
        $order = $this->order([44], 45);
        $item = $order->items->first();
        $chosen = $item->goat->weights->first();
        $chosen->forceFill(['image' => 'goats/animals/pb-44.jpg'])->save();

        Livewire::actingAs($this->admin)
            ->test(ListOrders::class)
            ->callTableAction('updateStatus', $order, data: [
                'status' => 'processing',
                // FileUpload hands its value over as an array.
                'photo' => ['order-status/a-better-shot.jpg'],
                'animals' => [['item_id' => $item->id, 'animal_id' => $chosen->id]],
            ]);

        $step = $order->fresh()->statusHistories()->where('to_status', 'processing')->latest('id')->first();

        $this->assertSame('order-status/a-better-shot.jpg', $step->photo);
    }

    /** The buyer gets the animal, its picture and the code on its pen. */
    public function test_the_buyer_is_shown_the_animal_and_its_code(): void
    {
        $order = $this->order([44, 47], 45);
        $item = $order->items->first();
        $chosen = $item->goat->weights->firstWhere('weight_kg', 44);
        $chosen->forceFill(['image' => 'goats/animals/pb-44.jpg'])->save();

        $this->moveToProcessing($order, [
            ['item_id' => $item->id, 'animal_id' => $chosen->id],
        ]);

        Sanctum::actingAs($order->user);

        $animal = $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->json('data.items.0.animal');

        $this->assertSame('PB-44', $animal['tag']);
        $this->assertEqualsWithDelta(44, $animal['weight_kg'], 0.001);
        $this->assertStringContainsString('pb-44.jpg', $animal['image']);
        $this->assertStringContainsString($chosen->token, $animal['qr']);
        $this->assertStringContainsString($chosen->token, $animal['url']);
    }

    /** An animal from a different listing must never attach to this line. */
    public function test_an_animal_from_another_listing_is_refused(): void
    {
        $order = $this->order([44], 45);
        $item = $order->items->first();

        $stranger = GoatWeight::create([
            'goat_id' => Goat::where('id', '!=', $item->goat_id)->firstOrFail()->id,
            'weight_kg' => 45,
        ]);

        $this->moveToProcessing($order, [
            ['item_id' => $item->id, 'animal_id' => $stranger->id],
        ]);

        $this->assertNull($item->fresh()->goat_weight_id);
        $this->assertSame('available', $stranger->fresh()->status);
    }
}
