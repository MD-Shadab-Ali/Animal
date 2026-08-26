<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'quantity'   => $this->quantity,
            // Which weight this line is. Null on a fixed listing, where the
            // goat's own weight is the only one there is.
            'weight_kg'  => (float) $this->weight_kg > 0 ? (float) $this->weight_kg : null,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'goat'       => new GoatResource($this->whenLoaded('goat')),
        ];
    }
}
