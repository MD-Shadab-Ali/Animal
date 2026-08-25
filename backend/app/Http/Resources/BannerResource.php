<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'title'        => $this->title,
            'subtitle'     => $this->subtitle,
            'description'  => $this->description,
            'image'        => $this->image_url,
            'mobile_image' => $this->mobile_image_url,
            'button_text'  => $this->button_text,
            'button_link'  => $this->button_link,
            'text_align'   => $this->text_align,
            'overlay_color'=> $this->overlay_color,
        ];
    }
}
