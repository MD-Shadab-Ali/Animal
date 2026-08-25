<?php

namespace App\Http\Resources;

use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference'    => $this->reference,
            'amount'       => (float) $this->amount,
            'currency'     => $this->currency,
            'status'       => $this->status,
            'status_label' => Payout::STATUSES[$this->status] ?? $this->status,
            'method'       => $this->method,
            'items_count'  => $this->when(isset($this->items_count), $this->items_count),
            'paid_at'      => $this->paid_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
