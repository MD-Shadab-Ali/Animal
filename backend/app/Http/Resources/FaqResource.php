<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaqResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'group'    => $this->group,
            'question' => $this->question,
            'answer'   => $this->answer,
        ];
    }
}
