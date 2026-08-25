<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'order_number'   => $this->order_number,
            'status'         => $this->status,
            'status_label'   => Order::STATUSES[$this->status] ?? $this->status,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'is_cancellable' => $this->isCancellable(),

            'customer' => [
                'name'  => $this->customer_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
            ],

            'shipping' => [
                'address_line' => $this->address_line,
                'area'         => $this->area,
                'city'         => $this->city,
                'postal_code'  => $this->postal_code,
                'zone'         => $this->whenLoaded('deliveryZone', fn () => $this->deliveryZone?->name),
                'notes'        => $this->order_notes,
            ],

            'totals' => [
                'subtotal'        => (float) $this->subtotal,
                'discount'        => (float) $this->discount,
                'delivery_charge' => (float) $this->delivery_charge,
                'total'           => (float) $this->total,
                'paid'            => (float) $this->paid_amount,
                'advance_required' => $this->advance_required !== null ? (float) $this->advance_required : null,
                'balance_due'     => $this->balance_due,
                'currency'        => $this->currency,
            ],

            'items'      => OrderItemResource::collection($this->whenLoaded('items')),
            'history'    => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
            'placed_at'  => $this->created_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
