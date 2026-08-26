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

        // Cash on delivery has no account to send to: the rider takes the cash
        // and staff record it, which is what closes the order.
        PaymentMethod::updateOrCreate(
            ['code' => 'cod'],
            [
                'name'             => 'Cash on Delivery',
                'instructions'     => 'Settle the remaining balance in cash when your goat is delivered. Please keep the exact change ready.',
                'is_active'        => true,
                // Visible at checkout but not choosable: it settles an order,
                // it does not start one.
                'on_delivery_only' => true,
                'supports_payout'  => false,
                'requires_advance' => false,
                'sort_order'       => 1,
            ]
        );

        // Extra gateways ship disabled — enable and fill the config in the admin panel.
        // Nepali wallets, seeded switched off until their keys are added in the
        // admin panel under Configuration -> Payment methods.
        foreach ([
            ['esewa', 'eSewa', 'Pay from your eSewa wallet. You will be sent to eSewa to confirm.', false, 'straight away'],
            ['khalti', 'Khalti', 'Pay with Khalti wallet, card or mobile banking.', false, 'straight away'],
            // A wallet number stands alone; a bank account number needs the bank,
            // and unlike a wallet it does not settle while you watch.
            ['bank_transfer', 'Bank Transfer', 'Transfer to our bank account and share the reference number.', true, 'in 1-3 working days'],
        ] as $i => [$code, $name, $instructions, $needsBank, $refundEta]) {
            PaymentMethod::updateOrCreate(
                ['code' => $code],
                [
                    'name'            => $name,
                    'instructions'    => $instructions,
                    'is_active'       => false,
                    // Wallets and bank transfer move money both ways, so they are
                    // payout rails the moment an admin switches them on.
                    'supports_payout' => true,
                    'requires_bank_name' => $needsBank,
                    'refund_eta'         => $refundEta,
                    'sort_order'      => $i + 2,
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
