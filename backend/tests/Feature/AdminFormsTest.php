<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFormsTest extends TestCase
{
    use RefreshDatabase;

    /** Every resource's create and edit screen must actually render. */
    public function test_create_and_edit_screens_render(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('role', 'admin')->firstOrFail();

        $resources = [
            'goats'            => \App\Models\Goat::class,
            'orders'           => \App\Models\Order::class,
            'sellers'          => \App\Models\Seller::class,
            'categories'       => \App\Models\Category::class,
            'coupons'          => \App\Models\Coupon::class,
            'users'            => \App\Models\User::class,
            'banners'          => \App\Models\Banner::class,
            'home-sections'    => \App\Models\HomeSection::class,
            'pages'            => \App\Models\Page::class,
            'menus'            => \App\Models\Menu::class,
            'testimonials'     => \App\Models\Testimonial::class,
            'faqs'             => \App\Models\Faq::class,
            'posts'            => \App\Models\Post::class,
            'post-categories'  => \App\Models\PostCategory::class,
            'delivery-zones'   => \App\Models\DeliveryZone::class,
            'payment-methods'  => \App\Models\PaymentMethod::class,
            'rooms'            => \App\Models\Room::class,
            'bookings'         => \App\Models\Booking::class,
        ];

        $failures = [];

        foreach ($resources as $slug => $model) {
            $record = $model::first();

            foreach (array_filter([
                "/admin/{$slug}/create",
                $record ? "/admin/{$slug}/{$record->getKey()}/edit" : null,
            ]) as $url) {
                try {
                    $status = $this->actingAs($admin)->get($url)->getStatusCode();
                    if ($status !== 200) {
                        $failures[] = "{$url} returned {$status}";
                    }
                } catch (\Throwable $e) {
                    $failures[] = "{$url} threw: ".$e->getMessage();
                }
            }
        }

        $this->assertSame([], $failures, "Admin screens failed:\n".implode("\n", $failures));
    }
}
