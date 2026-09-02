<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GoatResource;
use App\Models\Goat;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GoatController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->integer('per_page', (int) Setting::get('goats_per_page', 12));
        $perPage = max(1, min($perPage, 48));

        $query = Goat::query()
            ->with('category', 'seller', 'weights')
            ->published();

        $query->when($request->filled('category'), fn ($q) => $q->whereHas(
            'category',
            fn ($c) => $c->where('slug', $request->string('category'))
        ));

        $query->when($request->filled('breed'), fn ($q) => $q->whereIn('breed', (array) $request->input('breed')));
        $query->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')));

        $query->when($request->filled('min_price'), fn ($q) => $q->where('price', '>=', $request->float('min_price')));
        $query->when($request->filled('max_price'), fn ($q) => $q->where('price', '<=', $request->float('max_price')));
        $query->when($request->filled('min_weight'), fn ($q) => $q->where('weight_kg', '>=', $request->float('min_weight')));
        $query->when($request->filled('max_weight'), fn ($q) => $q->where('weight_kg', '<=', $request->float('max_weight')));

        $query->when($request->boolean('featured'), fn ($q) => $q->featured());
        $query->when($request->boolean('in_stock'), fn ($q) => $q->inStock());

        $query->when($request->filled('search'), function ($q) use ($request) {
            $term = '%'.$request->string('search').'%';

            $q->where(fn ($sub) => $sub
                ->where('name', 'like', $term)
                ->orWhere('breed', 'like', $term)
                ->orWhere('sku', 'like', $term)
                ->orWhere('short_description', 'like', $term));
        });

        match ($request->string('sort')->toString()) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'weight_asc' => $query->orderBy('weight_kg'),
            'weight_desc' => $query->orderByDesc('weight_kg'),
            'popular' => $query->orderByDesc('views'),
            'latest' => $query->latest(),
            default => $query->orderBy('sort_order')->latest(),
        };

        return GoatResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function show(string $slug): GoatResource
    {
        $goat = Goat::query()
            ->with(['category', 'seller', 'images', 'approvedReviews.user', 'weights'])
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $goat->increment('views');

        return new GoatResource($goat);
    }

    /** Other goats in the same category, for the detail page. */
    public function related(string $slug): AnonymousResourceCollection
    {
        $goat = Goat::published()->where('slug', $slug)->firstOrFail();

        $related = Goat::with('category', 'seller', 'weights')
            ->published()
            ->where('category_id', $goat->category_id)
            ->whereKeyNot($goat->getKey())
            ->inRandomOrder()
            ->limit(4)
            ->get();

        return GoatResource::collection($related);
    }

    /** Filter options built from what is actually in stock right now. */
    public function filters(): JsonResponse
    {
        $breeds = Goat::published()
            ->whereNotNull('breed')
            ->distinct()
            ->orderBy('breed')
            ->pluck('breed');

        $priceRange = Goat::published()->selectRaw('MIN(price) as min, MAX(price) as max')->first();
        $weightRange = Goat::published()->selectRaw('MIN(weight_kg) as min, MAX(weight_kg) as max')->first();

        return response()->json([
            'data' => [
                'breeds' => $breeds,
                'price' => [
                    'min' => (float) ($priceRange->min ?? 0),
                    'max' => (float) ($priceRange->max ?? 0),
                ],
                'weight' => [
                    'min' => (float) ($weightRange->min ?? 0),
                    'max' => (float) ($weightRange->max ?? 0),
                ],
                'genders' => ['male', 'female'],
                'sorts' => [
                    ['value' => 'default',     'label' => 'Recommended'],
                    ['value' => 'latest',      'label' => 'Newest first'],
                    ['value' => 'price_asc',   'label' => 'Price: low to high'],
                    ['value' => 'price_desc',  'label' => 'Price: high to low'],
                    ['value' => 'weight_asc',  'label' => 'Weight: light to heavy'],
                    ['value' => 'weight_desc', 'label' => 'Weight: heavy to light'],
                    ['value' => 'popular',     'label' => 'Most viewed'],
                ],
            ],
        ]);
    }
}
