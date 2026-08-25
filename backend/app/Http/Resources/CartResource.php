<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subtotal = $this->subtotal;
        $coupon   = $this->coupon;
        $discount = $coupon && $coupon->isRedeemable($subtotal, $this->user_id)
            ? $coupon->discountFor($subtotal)
            : 0.0;

        return [
            'id'    => $this->id,
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'coupon' => $coupon ? [
                'code'        => $coupon->code,
                'description' => $coupon->description,
            ] : null,
            'totals' => [
                'subtotal'       => round($subtotal, 2),
                'discount'       => round($discount, 2),
                'total'          => round($subtotal - $discount, 2),
                'total_quantity' => $this->total_quantity,
            ],
        ];
    }
}
