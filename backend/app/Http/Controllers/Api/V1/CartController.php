<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Goat;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): CartResource
    {
        return new CartResource($this->cartFor($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'goat_id'   => ['required', 'exists:goats,id'],
            'quantity'  => ['nullable', 'integer', 'min:1'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $goat = Goat::findOrFail($data['goat_id']);

        if (! $goat->is_available) {
            throw ValidationException::withMessages([
                'goat_id' => ['That goat is no longer available.'],
            ]);
        }

        // Listings sold by the kilo need to know which weight is being bought:
        // it decides the price, and two weights are two separate cart lines.
        $weight = 0.0;

        if ($goat->is_weight_priced) {
            $weight = (float) ($data['weight_kg'] ?? $goat->lightest_weight);

            if (! $goat->isWeightAllowed($weight)) {
                throw ValidationException::withMessages([
                    'weight_kg' => [$this->weightMessage($goat)],
                ]);
            }
        }

        $cart     = $this->cartFor($request);
        $quantity = $data['quantity'] ?? 1;

        $item = CartItem::firstOrNew([
            'cart_id'   => $cart->id,
            'goat_id'   => $goat->id,
            'weight_kg' => $weight,
        ]);

        $requested = ($item->quantity ?? 0) + $quantity;

        // Stock counts animals, not weights, so every line for this listing
        // draws on the same pool -- 25 kg and 37 kg are two of the five.
        $otherLines = CartItem::where('cart_id', $cart->id)
            ->where('goat_id', $goat->id)
            ->when($item->exists, fn ($query) => $query->whereKeyNot($item->getKey()))
            ->sum('quantity');

        if ($goat->track_stock && $requested + $otherLines > $goat->stock) {
            throw ValidationException::withMessages([
                'quantity' => ["Only {$goat->stock} of this goat is available."],
            ]);
        }

        $item->quantity = $requested;
        $item->save();

        return response()->json([
            'message' => $goat->name.' added to your cart.',
            'data'    => new CartResource($this->cartFor($request)),
        ], 201);
    }

    public function update(Request $request, CartItem $item): JsonResponse
    {
        $this->assertOwns($request, $item);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $goat = $item->goat;

        $otherLines = CartItem::where('cart_id', $item->cart_id)
            ->where('goat_id', $item->goat_id)
            ->whereKeyNot($item->getKey())
            ->sum('quantity');

        if ($goat->track_stock && $data['quantity'] + $otherLines > $goat->stock) {
            throw ValidationException::withMessages([
                'quantity' => ["Only {$goat->stock} of this goat is available."],
            ]);
        }

        $item->update(['quantity' => $data['quantity']]);

        return response()->json([
            'message' => 'Cart updated.',
            'data'    => new CartResource($this->cartFor($request)),
        ]);
    }

    public function destroy(Request $request, CartItem $item): JsonResponse
    {
        $this->assertOwns($request, $item);
        $item->delete();

        return response()->json([
            'message' => 'Removed from your cart.',
            'data'    => new CartResource($this->cartFor($request)),
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request);
        $cart->items()->delete();
        $cart->update(['coupon_id' => null]);

        return response()->json([
            'message' => 'Cart emptied.',
            'data'    => new CartResource($this->cartFor($request)),
        ]);
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        if (! Setting::get('enable_coupons', true)) {
            throw ValidationException::withMessages(['code' => ['Coupons are not accepted right now.']]);
        }

        $data = $request->validate(['code' => ['required', 'string']]);

        $cart   = $this->cartFor($request);
        $coupon = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower($data['code'])])->first();

        if (! $coupon || ! $coupon->isRedeemable($cart->subtotal, $request->user()->id)) {
            throw ValidationException::withMessages([
                'code' => ['That coupon cannot be used on this order.'],
            ]);
        }

        $cart->update(['coupon_id' => $coupon->id]);

        return response()->json([
            'message' => 'Coupon applied.',
            'data'    => new CartResource($this->cartFor($request)),
        ]);
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request);
        $cart->update(['coupon_id' => null]);

        return response()->json([
            'message' => 'Coupon removed.',
            'data'    => new CartResource($this->cartFor($request)),
        ]);
    }

    /** Says what the seller will actually supply, in the buyer's own terms. */
    private function weightMessage(Goat $goat): string
    {
        $step = rtrim(rtrim(number_format((float) ($goat->weight_step_kg ?: 1), 2, '.', ''), '0'), '.');

        return 'Choose a weight between '.(float) $goat->lightest_weight.' kg and '
            .(float) $goat->heaviest_weight.' kg, in steps of '.$step.' kg.';
    }

    private function cartFor(Request $request): Cart
    {
        $cart = Cart::firstOrCreate(['user_id' => $request->user()->id]);

        return $cart->load(['items.goat.category', 'coupon']);
    }

    private function assertOwns(Request $request, CartItem $item): void
    {
        abort_unless($item->cart->user_id === $request->user()->id, 403);
    }
}
