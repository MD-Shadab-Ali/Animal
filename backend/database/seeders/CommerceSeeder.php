<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\DeliveryZone;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class CommerceSeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            ['Kathmandu Valley', 'Kathmandu, Lalitpur and Bhaktapur, delivered by our own transport.', 1000, 50000, '1-2 days', 1],
            ['Around the Valley', 'Dhading, Kavre, Nuwakot and Makwanpur.', 2000, 80000, '2-3 days', 2],
            ['Rest of Nepal', 'Nationwide livestock transport.', 3500, null, '3-5 days', 3],
        ];

        foreach ($zones as [$name, $desc, $charge, $free, $time, $order]) {
            DeliveryZone::updateOrCreate(
                ['name' => $name],
                [
                    'description'    => $desc,
                    'charge'         => $charge,
                    'free_above'     => $free,
                    'estimated_time' => $time,
                    'is_active'      => true,
                    'sort_order'     => $order,
                ]
            );
        }

        PaymentMethod::updateOrCreate(
            ['code' => 'cod'],
            [
                'name'             => 'Cash on Delivery',
                'instructions'     => 'Pay the full amount in cash when the goat is delivered. Please keep exact change ready.',
                'is_active'        => true,
                'requires_advance' => false,
                'sort_order'       => 1,
            ]
        );

        // Extra gateways ship disabled — enable and fill the config in the admin panel.
        // Nepali wallets, seeded switched off until their keys are added in the
        // admin panel under Configuration -> Payment methods.
        foreach ([
            ['esewa', 'eSewa', 'Pay from your eSewa wallet. You will be sent to eSewa to confirm.'],
            ['khalti', 'Khalti', 'Pay with Khalti wallet, card or mobile banking.'],
            ['bank_transfer', 'Bank Transfer', 'Transfer to our bank account and share the reference number.'],
        ] as $i => [$code, $name, $instructions]) {
            PaymentMethod::updateOrCreate(
                ['code' => $code],
                [
                    'name'         => $name,
                    'instructions' => $instructions,
                    'is_active'    => false,
                    'sort_order'   => $i + 2,
                ]
            );
        }

        Coupon::updateOrCreate(
            ['code' => 'WELCOME5'],
            [
                'description'          => '5% off your first goat',
                'type'                 => 'percent',
                'value'                => 5,
                'min_order_amount'     => 15000,
                'max_discount'         => 5000,
                'usage_limit_per_user' => 1,
                'is_active'            => true,
            ]
        );
    }
}
