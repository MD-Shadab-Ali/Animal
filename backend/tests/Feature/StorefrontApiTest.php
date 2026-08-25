<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorefrontApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_public_endpoints_return_data(): void
    {
        $this->getJson('/api/v1/site')
            ->assertOk()
            ->assertJsonPath('data.settings.site_name', 'Goat Haven')
            ->assertJsonStructure(['data' => ['settings', 'menus', 'footer_pages']]);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonStructure(['data' => [['type', 'title', 'config', 'data']]]);

        $this->getJson('/api/v1/goats')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'effective_price', 'is_available']]]);

        $this->getJson('/api/v1/goats/filters')
            ->assertOk()
            ->assertJsonStructure(['data' => ['breeds', 'price', 'weight', 'sorts']]);

        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/pages/about-us')->assertOk()->assertJsonPath('data.slug', 'about-us');
        $this->getJson('/api/v1/posts')->assertOk();
        $this->getJson('/api/v1/faqs')->assertOk();
    }

    public function test_goat_detail_includes_description_and_bumps_views(): void
    {
        $goat = Goat::published()->firstOrFail();

        $this->getJson('/api/v1/goats/'.$goat->slug)
            ->assertOk()
            ->assertJsonPath('data.slug', $goat->slug)
            ->assertJsonStructure(['data' => ['description', 'images', 'rating']]);

        $this->assertSame($goat->views + 1, $goat->fresh()->views);
    }

    public function test_shop_filters_narrow_the_result_set(): void
    {
        $femaleCount = Goat::published()->where('gender', 'female')->count();

        $this->getJson('/api/v1/goats?gender=female&per_page=48')
            ->assertOk()
            ->assertJsonCount($femaleCount, 'data');

        $this->getJson('/api/v1/goats?search=jamunapari&per_page=48')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_cart_and_checkout_require_authentication(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
        $this->postJson('/api/v1/checkout', [])->assertUnauthorized();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_a_customer_can_register_and_receives_a_token(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'New Buyer',
            'email'                 => 'buyer@example.test',
            'phone'                 => '+880 1900-000000',
            'password'              => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['user' => ['id', 'name'], 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'buyer@example.test', 'role' => 'customer']);
    }

    public function test_full_purchase_journey(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();
        Sanctum::actingAs($customer);

        $goat = Goat::published()->inStock()->firstOrFail();
        $zone = DeliveryZone::active()->firstOrFail();

        $this->postJson('/api/v1/cart', ['goat_id' => $goat->id])
            ->assertCreated()
            ->assertJsonPath('data.totals.total_quantity', 1);

        $subtotal = $this->getJson('/api/v1/cart')->assertOk()->json('data.totals.subtotal');
        $this->assertEquals($goat->effective_price, $subtotal);

        $this->getJson('/api/v1/checkout/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['delivery_zones', 'payment_methods']])
            ->assertJsonPath('data.payment_methods.0.code', 'cod');

        $order = $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Rahim Uddin',
            'customer_phone'   => '+880 1811-111111',
            'address_line'     => 'House 12, Road 4, Dhanmondi',
            'city'             => 'Dhaka',
            'delivery_zone_id' => $zone->id,
            'payment_method'   => 'cod',
        ])->assertCreated()->json('data');

        $this->assertSame('pending', $order['status']);
        $this->assertSame('cod', $order['payment_method']);
        $this->assertEquals($goat->effective_price, $order['totals']['subtotal']);

        $this->assertSame('sold', $goat->fresh()->status);
        $this->assertSame(0, $goat->fresh()->stock);

        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.totals.total_quantity', 0);

        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/orders/'.$order['order_number'])
            ->assertOk()
            ->assertJsonPath('data.history.0.status', 'pending');
    }

    public function test_cancelling_an_order_restocks_the_goat(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();
        Sanctum::actingAs($customer);

        $goat = Goat::published()->inStock()->firstOrFail();
        $zone = DeliveryZone::active()->firstOrFail();

        $this->postJson('/api/v1/cart', ['goat_id' => $goat->id])->assertCreated();

        $order = $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Rahim Uddin',
            'customer_phone'   => '+880 1811-111111',
            'address_line'     => 'House 12',
            'city'             => 'Dhaka',
            'delivery_zone_id' => $zone->id,
            'payment_method'   => 'cod',
        ])->json('data');

        $this->assertSame('sold', $goat->fresh()->status);

        $this->postJson('/api/v1/orders/'.$order['order_number'].'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('published', $goat->fresh()->status);
        $this->assertSame(1, $goat->fresh()->stock);
    }

    public function test_coupon_discount_applies_to_the_cart(): void
    {
        $customer = User::where('role', 'customer')->firstOrFail();
        Sanctum::actingAs($customer);

        $goat = Goat::published()->where('price', '>=', 25000)->firstOrFail();

        $this->postJson('/api/v1/cart', ['goat_id' => $goat->id])->assertCreated();

        $cart = $this->postJson('/api/v1/cart/coupon', ['code' => 'welcome5'])
            ->assertOk()
            ->json('data');

        $expected = round(min($goat->effective_price * 0.05, 5000), 2);

        $this->assertSame('WELCOME5', $cart['coupon']['code']);
        $this->assertEquals($expected, $cart['totals']['discount']);
    }

    public function test_contact_form_reaches_the_admin_inbox(): void
    {
        $this->postJson('/api/v1/contact', [
            'name'    => 'Curious Buyer',
            'phone'   => '+880 1999-999999',
            'message' => 'Do you deliver to Rajshahi?',
        ])->assertCreated();

        $this->assertDatabaseHas('contact_messages', ['name' => 'Curious Buyer', 'is_read' => false]);
    }
}
