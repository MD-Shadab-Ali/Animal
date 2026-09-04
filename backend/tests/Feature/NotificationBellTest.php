<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Goat;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusChangedNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the shop tells a buyer, and where they find it again.
 *
 * These events already sent email. Email is gone the moment it is archived, so
 * the same notification classes now also write a row the storefront can read --
 * one source, two channels, and no way for the bell and the inbox to tell
 * different stories about the same event.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->buyer = User::where('role', 'customer')->firstOrFail();
    }

    private function order(string $status = 'confirmed'): Order
    {
        Goat::create([
            'category_id' => Category::first()->id,
            'name' => 'Bell Buck',
            'gender' => 'male',
            'price' => 5000,
            'stock' => 5,
            'track_stock' => true,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        return Order::create([
            'user_id' => $this->buyer->id,
            'order_number' => 'GH-BELL-'.strtoupper(uniqid()),
            'status' => $status,
            'payment_status' => 'unpaid',
            'payment_method' => 'esewa',
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '+977 9800-111111',
            'address_line' => 'House 12',
            'city' => 'Kathmandu',
            'subtotal' => 5000,
            'total' => 5000,
            'currency' => 'NPR',
        ]);
    }

    private function stranger(string $email): User
    {
        return User::create([
            'name' => 'Stranger',
            'email' => $email,
            'password' => bcrypt('secret1234'),
            'role' => 'customer',
            // EnsureUserIsActive answers 401 without these, so a stranger
            // would be turned away at the door and the test would pass for
            // entirely the wrong reason.
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** Every move an order makes is written down where the buyer can see it. */
    public function test_a_status_change_reaches_the_bell(): void
    {
        $order = $this->order();

        $order->update(['status' => 'processing']);

        Sanctum::actingAs($this->buyer);

        $body = $this->getJson('/api/v1/notifications')->assertOk()->json();

        $this->assertSame(1, $body['meta']['unread']);
        $this->assertSame('order', $body['data'][0]['kind']);
        $this->assertStringContainsString('Processing', $body['data'][0]['title']);
        $this->assertSame('/account/orders/'.$order->order_number, $body['data'][0]['url']);
        $this->assertFalse($body['data'][0]['is_read']);
    }

    /**
     * The badge stops counting somewhere.
     *
     * A three-digit number in a 20px circle is not a number, it is a smudge.
     */
    public function test_the_badge_caps_itself(): void
    {
        $order = $this->order();

        foreach (range(1, 101) as $ignored) {
            $this->buyer->notify(new OrderStatusChangedNotification($order, 'confirmed'));
        }

        Sanctum::actingAs($this->buyer);

        $meta = $this->getJson('/api/v1/notifications')->assertOk()->json('meta');

        $this->assertSame(101, $meta['unread']);
        $this->assertSame('99+', $meta['unread_badge']);
    }

    public function test_reading_one_clears_it_and_lowers_the_count(): void
    {
        $this->order()->update(['status' => 'processing']);

        Sanctum::actingAs($this->buyer);

        $id = $this->getJson('/api/v1/notifications')->json('data.0.id');

        $body = $this->postJson('/api/v1/notifications/'.$id.'/read')->assertOk()->json();

        $this->assertTrue($body['data']['is_read']);
        $this->assertSame(0, $body['meta']['unread']);
    }

    /** Opening the same one twice is the most ordinary thing a person can do. */
    public function test_reading_one_twice_is_not_an_error(): void
    {
        $this->order()->update(['status' => 'processing']);

        Sanctum::actingAs($this->buyer);

        $id = $this->getJson('/api/v1/notifications')->json('data.0.id');

        $this->postJson('/api/v1/notifications/'.$id.'/read')->assertOk();
        $this->postJson('/api/v1/notifications/'.$id.'/read')->assertOk();
    }

    public function test_marking_all_read_empties_the_badge(): void
    {
        $order = $this->order();
        $order->update(['status' => 'processing']);
        $order->update(['status' => 'out_for_delivery']);

        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('meta.unread', 0);
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 0);
    }

    /**
     * One buyer never sees another's.
     *
     * The guard that matters most here: these rows name orders, money and
     * addresses, and the whole feature is worthless if it is also a leak.
     */
    public function test_a_buyer_never_sees_another_buyers_notifications(): void
    {
        $this->order()->update(['status' => 'processing']);

        Sanctum::actingAs($this->stranger('stranger@example.test'));

        $body = $this->getJson('/api/v1/notifications')->assertOk()->json();

        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['meta']['unread']);
    }

    /** And cannot mark one of theirs read by guessing its id. */
    public function test_a_stranger_cannot_read_somebody_elses(): void
    {
        $this->order()->update(['status' => 'processing']);

        $id = DB::table('notifications')->where('notifiable_id', $this->buyer->id)->value('id');

        Sanctum::actingAs($this->stranger('stranger2@example.test'));

        $this->postJson('/api/v1/notifications/'.$id.'/read')->assertNotFound();

        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    /** The bell is for people with accounts. */
    public function test_the_bell_is_closed_to_strangers(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    /**
     * A staff row must never hand a buyer a link into the admin panel.
     *
     * Those rows already exist in this table by the hundred, written for a
     * different audience and pointing at a different application.
     */
    public function test_an_admin_link_is_never_offered_to_a_buyer(): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\NewOrderNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->buyer->id,
            'data' => json_encode(['title' => 'New order', 'body' => 'x', 'url' => '/admin/orders/1']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->buyer);

        $row = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');

        $this->assertNull($row['url']);
        $this->assertSame('general', $row['kind']);
    }
}
