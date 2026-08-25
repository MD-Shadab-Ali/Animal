<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'label'        => $this->label,
            'full_name'    => $this->full_name,
            'phone'        => $this->phone,
            'address_line' => $this->address_line,
            'area'         => $this->area,
            'city'         => $this->city,
            'postal_code'  => $this->postal_code,
            'notes'        => $this->notes,
            'is_default'   => $this->is_default,
        ];
    }
}
