<?php

namespace App\Http\Resources;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /** The state of the order this line belongs to, if the caller knows it. */
    private ?string $orderStatus = null;

    public function forOrder(?string $status): static
    {
        $this->orderStatus = $status;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'goat_id' => $this->goat_id,
            'name' => $this->goat_name,
            'sku' => $this->goat_sku,
            'thumbnail' => $this->thumbnail_url,
            'slug' => $this->whenLoaded('goat', fn () => $this->goat?->slug),
            // What was agreed at the time, not what the listing says now.
            'weight_kg' => $this->weight_kg !== null ? (float) $this->weight_kg : null,

            /*
             * The particular goat set aside for this line, once staff have
             * picked one at the Preparing step. Null until then, and on every
             * line whose listing keeps no individual animals.
             *
             * Its weight can differ from weight_kg above -- that is what the
             * buyer asked for, this is the animal they are getting -- and the
             * difference is settled on the scale at delivery.
             */
            'animal' => $this->whenLoaded('goatWeight', fn () => $this->goatWeight ? [
                'tag' => $this->goatWeight->tag,
                'weight_kg' => (float) $this->goatWeight->weight_kg,
                'image' => $this->goatWeight->image_url,
                // The same code as the tag on the pen, so a buyer can hold the
                // two side by side and see they match.
                'qr' => route('api.v1.animals.qr', ['token' => $this->goatWeight->token]),
                'url' => $this->goatWeight->publicUrl(),
            ] : null),
            'price_per_kg' => $this->price_per_kg !== null ? (float) $this->price_per_kg : null,
            // What the scale said on arrival, and which way it moved. A live
            // animal loses gut fill and water on the road, so this rarely
            // matches to the gram and the buyer should see both figures.
            'delivered_weight_kg' => $this->delivered_weight_kg !== null
                ? (float) $this->delivered_weight_kg
                : null,
            'weight_direction' => $this->weight_direction,
            // Signed: negative when the goat came in lighter and money came
            // off. The agreed line total stays above it, untouched.
            'price_adjustment' => (float) $this->price_adjustment,
            'charged_total' => $this->charged_line_total,
            'unit_price' => (float) $this->unit_price,
            'quantity' => $this->quantity,
            'line_total' => (float) $this->line_total,

            // Whoever supplied this goat -- a seller or the farm -- moves this
            // along, and the buyer should be able to watch it happen.
            'supplied_by' => $this->seller_name ?: Setting::get('site_name'),
            'fulfilment' => $this->fulfilmentBlock(),
        ];
    }

    /**
     * How far along this goat is, from the buyer's side of the fence.
     *
     * The stored state is the *supplier's* job, and that job genuinely ends at
     * "handed to the courier" — which is why nothing is stored beyond it, and
     * why delivery is not duplicated onto every line: it is one fact about the
     * order, with one timestamp.
     *
     * It would just be wrong to keep telling the buyer their goat is with the
     * courier once it is standing in their yard.
     */
    private function fulfilmentBlock(): array
    {
        $status = $this->fulfilment_status;
        $label = $this->fulfilment_label;

        if ($this->orderStatus === 'delivered' && $status !== 'cancelled') {
            $status = 'delivered';
            $label = 'Delivered';
        }

        return [
            'status' => $status,
            'label' => $label,
            'updated_at' => $this->fulfilment_updated_at?->toIso8601String(),
        ];
    }
}
