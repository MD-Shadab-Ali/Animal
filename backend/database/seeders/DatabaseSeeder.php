<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            UserSeeder::class,
            CommerceSeeder::class,
            CatalogSeeder::class,
            SellerSeeder::class,
            ContentSeeder::class,
            NavigationSeeder::class,
            PageSeeder::class,
            BlogSeeder::class,
        ]);
    }
}
