<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userWithRole(UserRole $role): User
    {
        return User::create([
            'name'      => $role->label().' Person',
            'email'     => $role->value.'@goathaven.test',
            'phone'     => '+880 1700-000001',
            'password'  => 'password',
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    public function test_a_customer_cannot_open_the_panel(): void
    {
        $customer = User::where('role', UserRole::Customer)->firstOrFail();

        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    public function test_staff_see_only_sales_and_inbox(): void
    {
        $staff = $this->userWithRole(UserRole::Staff);

        // Allowed
        $this->actingAs($staff)->get('/admin/orders')->assertOk();
        $this->actingAs($staff)->get('/admin/contact-messages')->assertOk();

        // Blocked
        $this->actingAs($staff)->get('/admin/goats')->assertForbidden();
        $this->actingAs($staff)->get('/admin/banners')->assertForbidden();
        $this->actingAs($staff)->get('/admin/payment-methods')->assertForbidden();
        $this->actingAs($staff)->get('/admin/manage-settings')->assertForbidden();
    }

    public function test_a_manager_runs_the_shop_but_not_the_configuration(): void
    {
        $manager = $this->userWithRole(UserRole::Manager);

        $this->actingAs($manager)->get('/admin/goats')->assertOk();
        $this->actingAs($manager)->get('/admin/orders')->assertOk();
        $this->actingAs($manager)->get('/admin/banners')->assertOk();
        $this->actingAs($manager)->get('/admin/posts')->assertOk();

        $this->actingAs($manager)->get('/admin/manage-settings')->assertForbidden();
        $this->actingAs($manager)->get('/admin/payment-methods')->assertForbidden();
        $this->actingAs($manager)->get('/admin/delivery-zones')->assertForbidden();
    }

    public function test_an_admin_reaches_everything(): void
    {
        $admin = User::where('role', UserRole::Admin)->firstOrFail();

        foreach ([
            '/admin/goats', '/admin/orders', '/admin/banners', '/admin/posts',
            '/admin/manage-settings', '/admin/payment-methods', '/admin/delivery-zones',
            '/admin/users',
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk("Admin was blocked from {$url}");
        }
    }

    public function test_a_disabled_account_loses_panel_access(): void
    {
        $manager = $this->userWithRole(UserRole::Manager);
        $manager->update(['is_active' => false]);

        $this->actingAs($manager->fresh())->get('/admin')->assertForbidden();
    }
}
