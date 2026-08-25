<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestimonialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name'        => $this->name,
            'designation' => $this->designation,
            'avatar'      => $this->avatar_url,
            'quote'       => $this->quote,
            'rating'      => $this->rating,
        ];
    }
}
