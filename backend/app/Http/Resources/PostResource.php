<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'title'       => $this->title,
            'slug'        => $this->slug,
            'excerpt'     => $this->excerpt,
            'body'        => $this->when($request->routeIs('*.posts.show'), $this->body),
            'cover_image' => $this->cover_image_url,
            'is_featured' => $this->is_featured,
            'category'    => $this->whenLoaded('category', fn () => [
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
            ]),
            'author'       => $this->whenLoaded('author', fn () => $this->author?->name),
            'published_at' => $this->published_at?->toIso8601String(),
            'seo'          => [
                'title'       => $this->meta_title ?: $this->title,
                'description' => $this->meta_description ?: $this->excerpt,
            ],
        ];
    }
}
