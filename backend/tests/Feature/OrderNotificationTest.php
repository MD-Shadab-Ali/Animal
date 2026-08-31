<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusChangedNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function placeOrder(): array
    {
        $customer = User::where('role', 'customer')->firstOrFail();
        Sanctum::actingAs($customer);

        $goat = Goat::published()->inStock()->firstOrFail();
        $zone = DeliveryZone::active()->firstOrFail();

        $this->postJson('/api/v1/cart', ['goat_id' => $goat->id])->assertCreated();

        $order = $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Rahim Uddin',
            'customer_email'   => 'rahim@example.test',
            'area'             => 'Ward 4',
            'postal_code'      => '44600',
            'customer_phone'   => '+880 1811-111111',
            'address_line'     => 'House 12',
            'city'             => 'Dhaka',
            'delivery_zone_id' => $zone->id,
            'payment_method'   => 'cod',
        ])->assertCreated()->json('data');

        return [$customer, $goat, $order];
    }

    public function test_placing_an_order_emails_the_customer_and_the_farm(): void
    {
        Notification::fake();

        [$customer] = $this->placeOrder();

        Notification::assertSentTo($customer, OrderPlacedNotification::class);

        $admin = User::where('role', 'admin')->firstOrFail();
        Notification::assertSentTo($admin, NewOrderNotification::class);
    }

    public function test_selling_the_last_goat_raises_a_low_stock_alert(): void
    {
        Notification::fake();

        [, $goat] = $this->placeOrder();

        $admin = User::where('role', 'admin')->firstOrFail();

        Notification::assertSentTo($admin, LowStockNotification::class,
            fn (LowStockNotification $n) => $n->goat->is($goat));
    }

    public function test_a_status_change_emails_the_customer(): void
    {
        [$customer, , $placed] = $this->placeOrder();

        Notification::fake();

        Order::where('order_number', $placed['order_number'])
            ->firstOrFail()
            ->update(['status' => 'out_for_delivery']);

        Notification::assertSentTo($customer, OrderStatusChangedNotification::class,
            function (OrderStatusChangedNotification $n) {
                return $n->order->status === 'out_for_delivery'
                    && $n->previousStatus === 'pending';
            });
    }

    public function test_no_confirmation_is_sent_when_checkout_fails(): void
    {
        Notification::fake();

        $customer = User::where('role', 'customer')->firstOrFail();
        Sanctum::actingAs($customer);

        // Empty cart — checkout must fail before anything is emailed.
        $this->postJson('/api/v1/checkout', [
            'customer_name'    => 'Rahim Uddin',
            'customer_email'   => 'rahim@example.test',
            'area'             => 'Ward 4',
            'postal_code'      => '44600',
            'customer_phone'   => '+880 1811-111111',
            'address_line'     => 'House 12',
            'city'             => 'Dhaka',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method'   => 'cod',
        ])->assertStatus(422);

        Notification::assertNothingSent();
    }
}
