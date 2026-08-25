<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@goathaven.test'],
            [
                'name'      => 'Farm Admin',
                'role'      => 'admin',
                'phone'     => '+977 9800-000000',
                'password'  => 'password',
                'is_active' => true,
            ]
        );

        $customer = User::updateOrCreate(
            ['email' => 'customer@example.test'],
            [
                'name'      => 'Rahim Uddin',
                'role'      => 'customer',
                'phone'     => '+977 9801-111111',
                'password'  => 'password',
                'is_active' => true,
            ]
        );

        // updateOrCreate, not firstOrCreate: the seeder should be able to correct
        // an address that already exists rather than silently skipping it.
        Address::updateOrCreate(
            ['user_id' => $customer->id, 'label' => 'Home'],
            [
                'full_name'    => 'Rahim Uddin',
                'phone'        => '+977 9801-111111',
                'address_line' => 'House 12, Ward 4, Patan',
                'area'         => 'Patan',
                'city'         => 'Kathmandu',
                'postal_code'  => '44700',
                'is_default'   => true,
            ]
        );
    }
}
