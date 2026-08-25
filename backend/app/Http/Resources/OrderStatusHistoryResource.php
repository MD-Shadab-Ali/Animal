<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status'     => $this->to_status,
            'label'      => Order::STATUSES[$this->to_status] ?? $this->to_status,
            'note'       => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
