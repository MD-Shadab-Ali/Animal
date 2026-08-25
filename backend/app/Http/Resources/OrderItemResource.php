<?php

namespace App\Http\Resources;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'goat_id'    => $this->goat_id,
            'name'       => $this->goat_name,
            'sku'        => $this->goat_sku,
            'thumbnail'  => $this->thumbnail_url,
            'slug'       => $this->whenLoaded('goat', fn () => $this->goat?->slug),
            'unit_price' => (float) $this->unit_price,
            'quantity'   => $this->quantity,
            'line_total' => (float) $this->line_total,

            // Whoever supplied this goat -- a seller or the farm -- moves this
            // along, and the buyer should be able to watch it happen.
            'supplied_by' => $this->seller_name ?: Setting::get('site_name'),
            'fulfilment'  => [
                'status'     => $this->fulfilment_status,
                'label'      => $this->fulfilment_label,
                'updated_at' => $this->fulfilment_updated_at?->toIso8601String(),
            ],
        ];
    }
}
