<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::active()
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->where('is_active', true)])
            ->withCount(['goats' => fn ($q) => $q->where('status', 'published')])
            ->orderBy('sort_order')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function show(string $slug): CategoryResource
    {
        $category = Category::active()
            ->with(['children' => fn ($q) => $q->where('is_active', true)])
            ->withCount(['goats' => fn ($q) => $q->where('status', 'published')])
            ->where('slug', $slug)
            ->firstOrFail();

        return new CategoryResource($category);
    }
}
