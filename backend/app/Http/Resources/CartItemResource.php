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
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'goat'       => new GoatResource($this->whenLoaded('goat')),
        ];
    }
}
