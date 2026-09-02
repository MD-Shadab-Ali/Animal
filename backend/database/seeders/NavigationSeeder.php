<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        $menus = [
            'header' => [
                'name'  => 'Header navigation',
                'items' => [
                    ['Home', '/'],
                    ['Shop', '/shop'],
                    ['Categories', '/categories'],
                    // Beside the things the farm sells rather than at the end
                    // of the row: a bed is one of them now.
                    ['Homestay', '/homestay'],
                    ['Care guides', '/blog'],
                    ['Sell with us', '/sell'],
                    ['About', '/pages/about-us'],
                    ['Contact', '/contact'],
                ],
            ],
            'footer_quick_links' => [
                'name'  => 'Footer — Quick links',
                'items' => [
                    ['All goats', '/shop'],
                    ['Dashain goats', '/shop?category=dashain-goats'],
                    ['Qurbani goats', '/shop?category=qurbani-goats'],
                    ['Dairy goats', '/shop?category=dairy-goats'],
                    ['Care guides', '/blog'],
                ],
            ],
            'footer_support' => [
                'name'  => 'Footer — Support',
                'items' => [
                    ['About us', '/pages/about-us'],
                    ['Delivery information', '/pages/delivery-information'],
                    ['Terms & conditions', '/pages/terms-and-conditions'],
                    ['Privacy policy', '/pages/privacy-policy'],
                    ['Contact us', '/contact'],
                ],
            ],
        ];

        foreach ($menus as $slug => $menu) {
            $record = Menu::updateOrCreate(['slug' => $slug], ['name' => $menu['name']]);

            foreach ($menu['items'] as $i => [$label, $url]) {
                MenuItem::updateOrCreate(
                    ['menu_id' => $record->id, 'label' => $label],
                    ['url' => $url, 'is_active' => true, 'sort_order' => $i]
                );
            }
        }
    }
}
