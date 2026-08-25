<?php

namespace Tests\Feature;

use App\Models\User;
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
}
