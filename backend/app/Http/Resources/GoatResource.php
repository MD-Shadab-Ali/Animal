<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'slug'              => $this->slug,
            'sku'               => $this->sku,
            'thumbnail'         => $this->thumbnail_url,
            'breed'             => $this->breed,
            'age_months'        => $this->age_months,
            'weight_kg'         => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            'gender'            => $this->gender,
            'color'             => $this->color,
            'teeth'             => $this->teeth,
            'health_status'     => $this->health_status,
            'is_vaccinated'     => $this->is_vaccinated,
            'specs'             => $this->specs ?? [],
            'price'             => (float) $this->price,
            'sale_price'        => $this->sale_price !== null ? (float) $this->sale_price : null,
            'effective_price'   => $this->effective_price,
            'is_on_sale'        => $this->is_on_sale,
            'discount_percent'  => $this->discount_percent,
            'stock'             => $this->stock,
            'track_stock'       => $this->track_stock,
            'is_available'      => $this->is_available,
            'status'            => $this->status,
            'is_featured'       => $this->is_featured,
            'short_description' => $this->short_description,
            'video_url'         => $this->video_url,
            'category'          => new CategoryResource($this->whenLoaded('category')),

            // Buyers should always know who they are buying from.
            'sold_by' => $this->seller_id === null
                ? ['type' => 'house', 'name' => \App\Models\Setting::get('site_name'), 'slug' => null, 'is_verified' => true]
                : $this->whenLoaded('seller', fn () => [
                    'type'        => 'seller',
                    'name'        => $this->seller->farm_name,
                    'slug'        => $this->seller->slug,
                    'city'        => $this->seller->city,
                    'logo'        => $this->seller->logo_url,
                    'is_verified' => $this->seller->status === 'approved',
                ]),

            // Only sent from the detail endpoint.
            'description'       => $this->when($request->routeIs('*.goats.show'), $this->description),
            'images'            => GoatImageResource::collection($this->whenLoaded('images')),
            'reviews'           => ReviewResource::collection($this->whenLoaded('approvedReviews')),
            'rating'            => $this->whenLoaded('approvedReviews', fn () => [
                'average' => round((float) $this->approvedReviews->avg('rating'), 1),
                'count'   => $this->approvedReviews->count(),
            ]),

            'seo' => [
                'title'       => $this->meta_title ?: $this->name,
                'description' => $this->meta_description ?: $this->short_description,
            ],
        ];
    }
}
