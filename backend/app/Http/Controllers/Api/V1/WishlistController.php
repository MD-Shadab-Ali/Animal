<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GoatResource;
use App\Models\Goat;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WishlistController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $goats = Goat::with('category', 'seller')
            ->whereIn('id', $request->user()->wishlists()->pluck('goat_id'))
            ->get();

        return GoatResource::collection($goats);
    }

    /** One endpoint for both adding and removing, so the heart button is a single call. */
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate(['goat_id' => ['required', 'exists:goats,id']]);

        $existing = Wishlist::where('user_id', $request->user()->id)
            ->where('goat_id', $data['goat_id'])
            ->first();

        if ($existing) {
            $existing->delete();

            return response()->json(['message' => 'Removed from your wishlist.', 'data' => ['in_wishlist' => false]]);
        }

        Wishlist::create([
            'user_id' => $request->user()->id,
            'goat_id' => $data['goat_id'],
        ]);

        return response()->json(['message' => 'Saved to your wishlist.', 'data' => ['in_wishlist' => true]]);
    }

    public function ids(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->wishlists()->pluck('goat_id')]);
    }
}
