<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'title'   => $this->title,
            'slug'    => $this->slug,
            'excerpt' => $this->excerpt,
            'body'    => $this->body,
            'banner'  => $this->banner_image_url,
            // Null when the page has none, which is how the storefront knows
            // to keep its single reading column instead of leaving a gap.
            'side_image' => $this->side_image_url,
            'side_image_caption' => $this->side_image_caption,
            'seo'     => [
                'title'       => $this->meta_title ?: $this->title,
                'description' => $this->meta_description ?: $this->excerpt,
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
