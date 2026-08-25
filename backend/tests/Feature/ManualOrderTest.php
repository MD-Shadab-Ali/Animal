<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\User;
use App\Notifications\OrderPlacedNotification;
use App\Services\ManualOrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManualOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function payload(Goat $goat, array $overrides = []): array
    {
        $customer = User::where('role', 'customer')->firstOrFail();

        return array_merge([
            'user_id'          => $customer->id,
            'customer_name'    => $customer->name,
            'customer_phone'   => $customer->phone,
            'customer_email'   => $customer->email,
            'address_line'     => 'House 12, Road 4',
            'city'             => 'Dhaka',
            'delivery_zone_id' => DeliveryZone::active()->firstOrFail()->id,
            'payment_method'   => 'cod',
            'items'            => [
                ['goat_id' => $goat->id, 'quantity' => 1],
            ],
        ], $overrides);
    }

    public function test_staff_can_take_a_phone_order_and_stock_moves(): void
    {
        Notification::fake();

        $goat = Goat::published()->inStock()->firstOrFail();
        $zone = DeliveryZone::active()->firstOrFail();

        $order = app(ManualOrderService::class)->create($this->payload($goat));

        $this->assertSame('pending', $order->status);
        $this->assertEquals($goat->effective_price, $order->subtotal);
        $this->assertEquals($zone->chargeFor($goat->effective_price), $order->delivery_charge);
        $this->assertEquals(
            $goat->effective_price + $zone->chargeFor($goat->effective_price),
            $order->total
        );

        $this->assertSame('sold', $goat->fresh()->status);
        $this->assertSame(0, $goat->fresh()->stock);

        // The line is a snapshot, not a live join.
        $this->assertSame($goat->name, $order->items->first()->goat_name);

        Notification::assertSentTo($order->user, OrderPlacedNotification::class);
    }

    public function test_a_negotiated_price_is_honoured(): void
    {
        Notification::fake();

        $goat = Goat::published()->inStock()->firstOrFail();

        $order = app(ManualOrderService::class)->create(
            $this->payload($goat, [
                'items'    => [['goat_id' => $goat->id, 'quantity' => 1, 'unit_price' => 12345]],
                'discount' => 345,
            ])
        );

        $this->assertEquals(12345, $order->subtotal);
        $this->assertEquals(345, $order->discount);
    }

    public function test_an_order_with_no_goats_is_rejected(): void
    {
        $goat = Goat::published()->inStock()->firstOrFail();

        $this->expectException(ValidationException::class);

        app(ManualOrderService::class)->create($this->payload($goat, ['items' => []]));
    }

    public function test_overselling_is_refused(): void
    {
        Notification::fake();

        $goat = Goat::published()->inStock()->firstOrFail();

        $this->expectException(ValidationException::class);

        app(ManualOrderService::class)->create(
            $this->payload($goat, ['items' => [['goat_id' => $goat->id, 'quantity' => 99]]])
        );
    }
}
