<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\FaqResource;
use App\Http\Resources\GoatResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\TestimonialResource;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Faq;
use App\Models\Goat;
use App\Models\HomeSection;
use App\Models\Post;
use App\Models\Testimonial;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    /**
     * The homepage is assembled entirely from admin-managed sections.
     * Each section carries its own resolved payload so the frontend
     * only has to pick a component by `type` and render it.
     */
    public function index(): JsonResponse
    {
        $sections = HomeSection::active()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (HomeSection $section) => [
                'type'             => $section->type,
                'title'            => $section->title,
                'subtitle'         => $section->subtitle,
                'description'      => $section->description,
                'background_color' => $section->background_color,
                'config'           => $section->config ?? [],
                'custom_html'      => $section->custom_html,
                'data'             => $this->dataFor($section),
            ])
            ->values();

        return response()->json(['data' => $sections]);
    }

    private function dataFor(HomeSection $section): mixed
    {
        $config = $section->config ?? [];
        $limit  = (int) ($config['limit'] ?? 8);

        return match ($section->type) {
            'hero_slider' => BannerResource::collection(
                Banner::live()->where('placement', 'hero')->orderBy('sort_order')->get()
            ),

            'promo_banner' => BannerResource::collection(
                Banner::live()
                    ->where('placement', $config['placement'] ?? 'promo_strip')
                    ->orderBy('sort_order')
                    ->get()
            ),

            'categories' => CategoryResource::collection(
                Category::active()
                    ->withCount(['goats' => fn ($q) => $q->where('status', 'published')])
                    ->orderBy('sort_order')
                    ->limit($limit)
                    ->get()
            ),

            'featured_goats' => GoatResource::collection(
                Goat::with('category', 'seller', 'weights')
                    ->published()
                    ->featured()
                    ->orderBy('sort_order')
                    ->limit($limit)
                    ->get()
            ),

            'latest_goats' => GoatResource::collection(
                Goat::with('category', 'seller', 'weights')
                    ->published()
                    ->latest()
                    ->limit($limit)
                    ->get()
            ),

            'testimonials' => TestimonialResource::collection(
                Testimonial::active()->orderBy('sort_order')->limit($limit)->get()
            ),

            'faq' => FaqResource::collection(
                Faq::active()
                    ->when($config['group'] ?? null, fn ($q, $group) => $q->where('group', $group))
                    ->orderBy('sort_order')
                    ->limit($limit)
                    ->get()
            ),

            'blog' => PostResource::collection(
                Post::with('category')->published()->latest('published_at')->limit($limit)->get()
            ),

            // why_choose_us, cta and custom_html render straight from `config`.
            default => null,
        };
    }
}
