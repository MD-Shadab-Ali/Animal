<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Goat;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * XML sitemap for the storefront. URLs point at the Next.js site, not this
     * API, so search engines index the shop rather than the backend.
     */
    public function index(): Response
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        $urls = [
            ['loc' => $base.'/', 'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => $base.'/shop', 'priority' => '0.9', 'changefreq' => 'daily'],
            ['loc' => $base.'/categories', 'priority' => '0.7', 'changefreq' => 'weekly'],
            ['loc' => $base.'/blog', 'priority' => '0.6', 'changefreq' => 'weekly'],
            ['loc' => $base.'/contact', 'priority' => '0.5', 'changefreq' => 'monthly'],
        ];

        foreach (Goat::published()->get(['slug', 'updated_at']) as $goat) {
            $urls[] = [
                'loc'        => $base.'/goats/'.$goat->slug,
                'lastmod'    => $goat->updated_at?->toAtomString(),
                'priority'   => '0.8',
                'changefreq' => 'weekly',
            ];
        }

        foreach (Category::active()->get(['slug', 'updated_at']) as $category) {
            $urls[] = [
                'loc'        => $base.'/shop?category='.$category->slug,
                'lastmod'    => $category->updated_at?->toAtomString(),
                'priority'   => '0.6',
                'changefreq' => 'weekly',
            ];
        }

        foreach (Page::active()->get(['slug', 'updated_at']) as $page) {
            $urls[] = [
                'loc'        => $base.'/pages/'.$page->slug,
                'lastmod'    => $page->updated_at?->toAtomString(),
                'priority'   => '0.4',
                'changefreq' => 'monthly',
            ];
        }

        foreach (Post::published()->get(['slug', 'updated_at']) as $post) {
            $urls[] = [
                'loc'        => $base.'/blog/'.$post->slug,
                'lastmod'    => $post->updated_at?->toAtomString(),
                'priority'   => '0.5',
                'changefreq' => 'monthly',
            ];
        }

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain']);
    }
}
