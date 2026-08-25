<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A goat as its owner sees it, with the approval state attached. */
class SellerListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'slug'              => $this->slug,
            'sku'               => $this->sku,
            'thumbnail'         => $this->thumbnail_url,
            'category'          => new CategoryResource($this->whenLoaded('category')),

            'breed'         => $this->breed,
            'age_months'    => $this->age_months,
            'weight_kg'     => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            'gender'        => $this->gender,
            'color'         => $this->color,
            'teeth'         => $this->teeth,
            'health_status' => $this->health_status,
            'is_vaccinated' => $this->is_vaccinated,
            'specs'         => $this->specs ?? [],

            'price'       => (float) $this->price,
            'sale_price'  => $this->sale_price !== null ? (float) $this->sale_price : null,
            'stock'       => $this->stock,
            'track_stock' => $this->track_stock,

            'short_description' => $this->short_description,
            'description'       => $this->description,
            'video_url'         => $this->video_url,

            'status'           => $this->status,
            'approval_status'  => $this->approval_status,
            'rejection_reason' => $this->rejection_reason,
            'is_live'          => $this->status === 'published' && $this->approval_status === 'approved',
            'is_editable'      => in_array($this->approval_status, ['draft', 'rejected'], true),

            'views'        => $this->views,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at'  => $this->approved_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),

            'images' => GoatImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
