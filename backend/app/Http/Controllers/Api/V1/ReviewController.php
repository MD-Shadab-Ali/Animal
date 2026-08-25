<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function store(Request $request, string $slug): JsonResponse
    {
        if (! Setting::get('enable_reviews', true)) {
            abort(404);
        }

        $goat = Goat::where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'title'   => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // Only customers who actually received this goat may review it.
        $order = Order::where('user_id', $request->user()->id)
            ->where('status', 'delivered')
            ->whereHas('items', fn ($q) => $q->where('goat_id', $goat->id))
            ->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'rating' => ['You can review a goat once it has been delivered to you.'],
            ]);
        }

        if (Review::where('user_id', $request->user()->id)->where('goat_id', $goat->id)->exists()) {
            throw ValidationException::withMessages([
                'rating' => ['You have already reviewed this goat.'],
            ]);
        }

        Review::create($data + [
            'goat_id'     => $goat->id,
            'user_id'     => $request->user()->id,
            'order_id'    => $order->id,
            'is_approved' => false,
        ]);

        return response()->json([
            'message' => 'Thanks. Your review will appear once we have checked it.',
        ], 201);
    }
}
