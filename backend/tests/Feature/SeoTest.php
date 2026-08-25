<?php

namespace Tests\Feature;

use App\Models\Goat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sitemap_lists_storefront_urls(): void
    {
        $this->seed(DatabaseSeeder::class);

        $goat = Goat::published()->firstOrFail();
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $response = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml');

        $response->assertSee($frontend.'/', false);
        $response->assertSee($frontend.'/goats/'.$goat->slug, false);
        $response->assertSee($frontend.'/pages/about-us', false);

        // It must never advertise the API or admin panel.
        $response->assertDontSee('/admin', false);
    }

    public function test_robots_blocks_the_admin_panel(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /admin')
            ->assertSee('Sitemap:');
    }

    public function test_draft_goats_stay_out_of_the_sitemap(): void
    {
        $this->seed(DatabaseSeeder::class);

        $goat = Goat::published()->firstOrFail();
        $goat->update(['status' => 'draft']);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/goats/'.$goat->slug, false);
    }
}
