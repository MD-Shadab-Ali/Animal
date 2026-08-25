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
            'seo'     => [
                'title'       => $this->meta_title ?: $this->title,
                'description' => $this->meta_description ?: $this->excerpt,
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
