<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the buyer's ledger, across every order they have placed.
 *
 * PaymentEntryResource answers "what has been paid on this order", so it can
 * take the order for granted. A ledger row cannot: read on its own it has to
 * say which order the money was for and what was bought, or it is a figure
 * with no story attached.
 *
 * Refunds belong here too. On an order page they have their own panel and
 * would read as another payment; in a ledger, leaving them out is what makes
 * the column stop adding up.
 */
class PaymentLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'order_number' => $this->order?->order_number,
            // Already written for a table cell: one goat, or the first and a
            // count of the rest.
            'goats' => $this->goats_summary,

            /*
             * A ledger row has to say what the money was for, and a payment for
             * a room has no order to name. Without these a stay would show up
             * as a dash with a figure beside it -- worse than the row being
             * missing, because it reads as a payment for nothing.
             */
            'booking_number' => $this->booking?->booking_number,
            'stay' => $this->booking?->room?->name,
            'type' => $this->type,
            'type_label' => Payment::TYPES[$this->type] ?? $this->type,
            'method' => $this->method,
            'method_label' => $this->method_label,
            'amount' => (float) $this->amount,
            // Money going the other way carries its minus, so a column of
            // these adds up to what the buyer is actually out of pocket.
            'signed_amount' => $this->signed_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            // Worded for the direction the money travelled: "Received" for a
            // payment, "Refunded" for a refund.
            'status_label' => $this->status_label,
            'transaction_reference' => $this->transaction_reference,
            'note' => $this->when($this->status === 'rejected', $this->note),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
        ];
    }
}
