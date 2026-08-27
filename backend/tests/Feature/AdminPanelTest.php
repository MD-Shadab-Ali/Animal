<?php

namespace Tests\Feature;

use App\Filament\Widgets\TopSellingGoats;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Livewire\Livewire;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function adminUser(): User
    {
        $this->seed(DatabaseSeeder::class);

        return User::where('role', 'admin')->firstOrFail();
    }

    public function test_login_page_is_reachable(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_customers_cannot_open_the_panel(): void
    {
        $this->seed(DatabaseSeeder::class);
        $customer = User::where('role', 'customer')->firstOrFail();

        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    public function test_every_resource_list_page_loads_for_an_admin(): void
    {
        $admin = $this->adminUser();

        $slugs = [
            'goats', 'categories', 'orders', 'coupons', 'users', 'reviews',
            'banners', 'home-sections', 'pages', 'menus', 'testimonials', 'faqs',
            'posts', 'post-categories', 'contact-messages', 'inquiries',
            'subscribers', 'delivery-zones', 'payment-methods', 'sellers', 'payouts', 'payments', 'refunds',
        ];

        foreach ($slugs as $slug) {
            $this->actingAs($admin)
                ->get("/admin/{$slug}")
                ->assertOk("Resource list page /admin/{$slug} failed to render");
        }
    }

    public function test_dashboard_and_settings_page_load(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/manage-settings')->assertOk();
    }

    public function test_goat_create_and_edit_screens_load(): void
    {
        $admin = $this->adminUser();
        $goat = \App\Models\Goat::firstOrFail();

        $this->actingAs($admin)->get('/admin/goats/create')->assertOk();
        $this->actingAs($admin)->get("/admin/goats/{$goat->id}/edit")->assertOk();
    }

    /**
     * The Best sellers widget has to render as a table, not as a stack of text.
     *
     * It used to be a hand-written Blade view styled with Tailwind utilities,
     * and this panel ships no custom theme -- none of those classes existed at
     * runtime, so the widget arrived on the dashboard unstyled. Mounting it
     * here also runs its grouped query, which a plain page-load assertion on
     * the dashboard never reaches: the widgets are lazy.
     */
    public function test_the_best_sellers_widget_ranks_by_what_was_actually_charged(): void
    {
        $admin = $this->adminUser();
        $zone  = DeliveryZone::active()->firstOrFail();
        $goats = Goat::query()->orderBy('id')->take(3)->get();

        // The board shows the live name, so that is what these are called.
        $goats[0]->update(['name' => 'Heavier At The Door']);
        $goats[1]->update(['name' => 'Steady Seller']);
        $goats[2]->update(['name' => 'Cancelled Winner']);

        // Middling order, but the goat came in heavier and was charged more --
        // enough to put it top. Ranking on the agreed figure would miss that.
        $this->sale($zone, $goats[0], 'Heavier At The Door', 'SKU-A', 40_000, 5_000);
        $this->sale($zone, $goats[1], 'Steady Seller', 'SKU-B', 42_000);
        $this->sale($zone, $goats[2], 'Cancelled Winner', 'SKU-C', 90_000, 0, 'cancelled');

        Livewire::actingAs($admin)
            ->test(TopSellingGoats::class)
            ->assertOk()
            ->assertSee('Best sellers')
            ->assertSee('SKU-A')
            ->assertSee('Steady Seller')
            // Excluded by the widget's own filter, and the biggest number on
            // the board -- so if it ever appears, it appears first.
            ->assertDontSee('Cancelled Winner')
            // Ranked on 40,000 agreed + 5,000 the scale added, so it sits above
            // the 42,000 line. Ranking on the agreed figure would invert these.
            ->assertSeeInOrder(['Heavier At The Door', 'Steady Seller'])
            ->assertSee(number_format(45000));
    }

    /**
     * A renamed goat is still one goat.
     *
     * `order_items.goat_name` is a snapshot taken when the order was placed,
     * so a goat that has been renamed since -- as every goat carrying a weight
     * in its name was -- appears under each name it was ever sold under.
     * Ranking on that split a real best seller into halves, and neither half
     * was big enough to reach the board: it vanished from the dashboard
     * entirely while outselling most of what was listed.
     */
    public function test_a_renamed_goat_keeps_all_of_its_sales_in_one_row(): void
    {
        $admin = $this->adminUser();
        $zone  = DeliveryZone::active()->firstOrFail();
        $goat  = Goat::firstOrFail();
        $goat->update(['name' => 'Black Bengal Buck']);

        // Two sales of the same goat under the name it had at the time, either
        // one of which is beaten by the rival below.
        $this->sale($zone, $goat, 'Black Bengal Buck — 22kg', $goat->sku, 49_000);
        $this->sale($zone, $goat, 'Black Bengal Buck', $goat->sku, 24_500);
        $rival = Goat::orderBy('id')->skip(1)->firstOrFail();
        $rival->update(['name' => 'Runner Up']);
        $this->sale($zone, $rival, 'Runner Up', 'SKU-RU', 60_000);

        Livewire::actingAs($admin)
            ->test(TopSellingGoats::class)
            ->assertOk()
            // 73,500 together, so it goes above the 60,000 rival. Split, both
            // halves sit below it and the goat drops off the board.
            ->assertSeeInOrder(['Black Bengal Buck', 'Runner Up'])
            ->assertSee(number_format(73500))
            // The live name, not the snapshot with the weight still on it.
            ->assertDontSee('Black Bengal Buck — 22kg');
    }

    public function test_a_goat_that_no_longer_exists_still_ranks(): void
    {
        $admin = $this->adminUser();
        $zone  = DeliveryZone::active()->firstOrFail();

        // Nothing to join a live name to, so the snapshot is all there is --
        // and two such lines must not collapse together on a shared null id.
        $this->sale($zone, null, 'Gone But Top', 'SKU-GONE', 80_000);
        $this->sale($zone, null, 'Also Gone', 'SKU-GONE-2', 70_000);

        Livewire::actingAs($admin)
            ->test(TopSellingGoats::class)
            ->assertOk()
            ->assertSeeInOrder(['Gone But Top', 'Also Gone'])
            ->assertSee(number_format(80000))
            ->assertSee(number_format(70000));
    }

    /** One delivered order for one goat, optionally re-priced or cancelled. */
    private function sale(
        DeliveryZone $zone,
        ?Goat $goat,
        string $name,
        string $sku,
        float $lineTotal,
        float $adjustment = 0,
        string $status = 'delivered',
    ): void {
        $order = Order::create([
            'user_id'           => User::where('role', 'customer')->firstOrFail()->id,
            'order_number'      => 'GH-TEST-'.$sku.'-'.$name,
            'customer_name'     => 'Test Buyer',
            'customer_phone'    => '+977 9800-000000',
            'address_line'      => 'House 1',
            'city'              => 'Kathmandu',
            'delivery_zone_id'  => $zone->id,
            'payment_method'    => 'cod',
            'payment_status'    => 'paid',
            'status'            => $status,
            'subtotal'          => $lineTotal,
            'weight_adjustment' => $adjustment,
            'discount'          => 0,
            'delivery_charge'   => 0,
            'total'             => $lineTotal + $adjustment,
        ]);

        OrderItem::create([
            'order_id'         => $order->id,
            'goat_id'          => $goat?->id,
            'goat_name'        => $name,
            'goat_sku'         => $sku,
            'unit_price'       => $lineTotal,
            'quantity'         => 1,
            'line_total'       => $lineTotal,
            'price_adjustment' => $adjustment,
        ]);
    }
}
