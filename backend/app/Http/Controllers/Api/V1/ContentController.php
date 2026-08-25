<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FaqResource;
use App\Http\Resources\PageResource;
use App\Http\Resources\PostResource;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Post;
use App\Models\PostCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContentController extends Controller
{
    public function page(string $slug): PageResource
    {
        return new PageResource(
            Page::active()->where('slug', $slug)->firstOrFail()
        );
    }

    public function posts(Request $request): AnonymousResourceCollection
    {
        $posts = Post::with('category', 'author')
            ->published()
            ->when($request->filled('category'), fn ($q) => $q->whereHas(
                'category',
                fn ($c) => $c->where('slug', $request->string('category'))
            ))
            ->when($request->filled('search'), fn ($q) => $q->where(
                'title',
                'like',
                '%'.$request->string('search').'%'
            ))
            ->latest('published_at')
            ->paginate(max(1, min($request->integer('per_page', 9), 24)))
            ->withQueryString();

        return PostResource::collection($posts);
    }

    public function post(string $slug): PostResource
    {
        $post = Post::with('category', 'author')
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $post->increment('views');

        return new PostResource($post);
    }

    public function postCategories(): JsonResponse
    {
        return response()->json([
            'data' => PostCategory::where('is_active', true)
                ->withCount(['posts' => fn ($q) => $q->where('is_published', true)])
                ->get()
                ->map(fn (PostCategory $c) => [
                    'name'        => $c->name,
                    'slug'        => $c->slug,
                    'posts_count' => $c->posts_count,
                ]),
        ]);
    }

    public function faqs(Request $request): AnonymousResourceCollection
    {
        $faqs = Faq::active()
            ->when($request->filled('group'), fn ($q) => $q->where('group', $request->string('group')))
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get();

        return FaqResource::collection($faqs);
    }
}
