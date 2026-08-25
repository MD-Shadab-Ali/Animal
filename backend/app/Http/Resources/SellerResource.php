<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public seller profile — nothing sensitive leaves through this. */
class SellerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'farm_name'   => $this->farm_name,
            'slug'        => $this->slug,
            'bio'         => $this->bio,
            'logo'        => $this->logo_url,
            'banner'      => $this->banner_url,
            'city'        => $this->city,
            'area'        => $this->area,
            'is_verified' => $this->status === 'approved',
            'member_since' => $this->approved_at?->toIso8601String() ?? $this->created_at?->toIso8601String(),
            'listings_count' => $this->when(isset($this->goats_count), $this->goats_count),
            'goats'       => GoatResource::collection($this->whenLoaded('goats')),
        ];
    }
}
