<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GoatResource;
use App\Http\Resources\SellerResource;
use App\Models\Goat;
use App\Models\Seller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerDirectoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $sellers = Seller::approved()
            ->withCount(['goats' => fn ($query) => $query->published()])
            ->orderByDesc('goats_count')
            ->get();

        return SellerResource::collection($sellers);
    }

    public function show(string $slug): SellerResource
    {
        $seller = Seller::approved()
            ->withCount(['goats' => fn ($query) => $query->published()])
            ->where('slug', $slug)
            ->firstOrFail();

        $seller->setRelation(
            'goats',
            Goat::with('category')->published()->where('seller_id', $seller->id)->latest()->get()
        );

        return new SellerResource($seller);
    }
}
