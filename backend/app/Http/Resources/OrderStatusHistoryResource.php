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
            /*
             * So the browser can tell one update from another across a refresh.
             *
             * Without it the list was keyed by position, and these arrive
             * newest first -- so every new update pushed the ones below it down
             * a place and changed their keys. React threw those rows away and
             * built them again, which meant the goat's photograph and its tag
             * code were fetched from scratch each time the order moved on, and
             * the buyer watched them go blank and come back.
             */
            'id' => $this->id,
            'status' => $this->to_status,
            'label' => Order::STATUSES[$this->to_status] ?? $this->to_status,
            'note' => $this->note,
            /*
             * Whether this came from the farm or from the buyer themselves.
             *
             * "Updates from the farm" is a promise about who is speaking. A
             * buyer pressing "I'm on my way" writes a note for staff, in the
             * third person, and it was being shown back to them under that
             * heading -- the shop telling them what they had just done.
             */
            'from_farm' => ! $this->isFromBuyer(),
            // Whatever staff photographed when they moved the order.
            'photo' => $this->photo_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
