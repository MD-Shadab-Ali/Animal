<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'image'       => $this->image_url,
            'icon'        => $this->icon,
            'is_featured' => $this->is_featured,
            'goats_count' => $this->when(isset($this->goats_count), $this->goats_count),
            'children'    => CategoryResource::collection($this->whenLoaded('children')),
            'seo'         => [
                'title'       => $this->meta_title ?: $this->name,
                'description' => $this->meta_description ?: $this->description,
            ],
        ];
    }
}
