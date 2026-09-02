<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Support\Homestay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'booking_number' => $this->booking_number,
            'status' => $this->status,
            'status_label' => $this->status_label,

            // The whole run, so a timeline can draw the steps still to come as
            // well as the ones behind. The same shape the order timeline reads.
            'flow' => Booking::FLOW,
            'statuses' => Booking::STATUSES,

            'room' => [
                'name' => $this->room_name,
                'thumbnail' => $this->room_thumbnail
                    ? asset('storage/'.$this->room_thumbnail)
                    : null,
                // Null once a room has been deleted outright. The snapshot
                // above still says what was booked; only the link goes.
                'slug' => $this->whenLoaded('room', fn () => $this->room?->slug),
            ],

            'stay' => [
                'check_in' => $this->check_in?->toDateString(),
                'check_out' => $this->check_out?->toDateString(),
                'nights' => (int) $this->nights,
                'guests' => (int) $this->guests,
                // The farm's hours rather than this guest's own arrangement,
                // which is why they come from settings and not the booking.
                'check_in_time' => Homestay::checkInTime(),
                'check_out_time' => Homestay::checkOutTime(),
                'checked_in_at' => $this->checked_in_at?->toIso8601String(),
                'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            ],

            'guest' => [
                'name' => $this->guest_name,
                'phone' => $this->guest_phone,
                'email' => $this->guest_email,
                'notes' => $this->guest_notes,
            ],

            'totals' => [
                'rate_per_night' => (float) $this->rate_per_night,
                'room_charge' => (float) $this->room_charge,
                'extra_guest_charge' => (float) $this->extra_guest_charge,
                'discount' => (float) $this->discount,
                'total' => (float) $this->total,
                'paid' => (float) $this->paid_amount,
                'balance_due' => $this->balance_due,
                // What is owed today, which on an advance plan is not the same
                // as what is owed altogether.
                'due_now' => $this->amount_due_now,
                'advance_required' => $this->advance_required !== null
                    ? (float) $this->advance_required
                    : null,
                'currency' => $this->currency,
            ],

            'payment' => [
                'method' => $this->payment_method,
                'plan' => $this->payment_plan,
                'plan_label' => $this->payment_plan_label,
                'status' => $this->payment_status,
                'awaiting_advance' => $this->awaiting_advance,
                'is_fully_paid' => $this->isFullyPaid(),
                // The storefront hides the "I have paid" form while a claim is
                // outstanding; the service refuses a second one regardless.
                'has_pending_claim' => $this->payments()->where('status', 'pending')->exists(),

                /*
                 * Where to send the money, for a method somebody pays by hand.
                 *
                 * Only on the detail payload -- it costs a lookup per booking,
                 * and a list of stays has no form to fill in. Null for a
                 * gateway, which collects the money itself: printing an account
                 * number beside a Pay button would invite exactly the manual
                 * transfer the integration exists to replace.
                 */
                'payee' => $this->when(
                    $this->resource->relationLoaded('payments'),
                    fn () => PaymentMethod::where('code', $this->payment_method)
                        ->first()?->payeeDetails(),
                ),

                'entries' => PaymentEntryResource::collection($this->whenLoaded('payments')),
            ],

            'can_cancel' => $this->isCancellable(),
            'is_refundable' => $this->isRefundable(),
            'refundable_amount' => $this->refundable_amount,
            'cancellation_note' => Homestay::cancellationNote(),
            'house_rules' => Homestay::houseRules(),

            'history' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories
                ->map(fn ($entry) => [
                    'from' => $entry->from_status,
                    'to' => $entry->to_status,
                    'label' => $entry->status_label,
                    'note' => $entry->note,
                    'at' => $entry->created_at?->toIso8601String(),
                ])->values()),

            'placed_at' => $this->created_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
