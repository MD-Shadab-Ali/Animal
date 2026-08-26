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
            // A listing advertised at one weight can often be supplied heavier.
            // The rate comes from the asking price and the advertised weight,
            // so there is nothing extra for a seller to keep in step.
            'pricing' => [
                'is_per_kg'     => $this->is_weight_priced,
                'price_per_kg'  => $this->price_per_kg !== null ? (float) $this->price_per_kg : null,
                // The weight the asking price belongs to. Prices scale from
                // here, and the selector opens here, so it is not the same
                // thing as the lightest weight on offer.
                'anchor_weight_kg' => $this->is_weight_priced ? $this->anchor_weight : null,
                'min_weight_kg' => $this->is_weight_priced ? $this->lightest_weight : null,
                'max_weight_kg' => $this->is_weight_priced ? $this->heaviest_weight : null,
                'step_kg'       => $this->is_weight_priced ? (float) ($this->weight_step_kg ?: 1) : null,
                // What it costs at the lightest and the heaviest on offer.
                'from_price'    => $this->is_weight_priced ? $this->effective_price : null,
                'to_price'      => $this->is_weight_priced ? $this->heaviest_price : null,
                // Only worth sending on the detail page, where the selector lives.
                'options'       => $this->when(
                    $request->routeIs('*.goats.show') && $this->is_weight_priced,
                    fn () => $this->weightOptions()
                ),
            ],

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
