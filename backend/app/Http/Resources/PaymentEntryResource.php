<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One line of the customer's own payment history on an order. */
class PaymentEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference'    => $this->reference,
            'amount'       => (float) $this->amount,
            'currency'     => $this->currency,
            'type'         => $this->type,
            'method'       => $this->method,
            'method_label' => $this->method_label,
            'status'       => $this->status,
            'status_label' => $this->status_label,
            'transaction_reference' => $this->transaction_reference,
            'note'         => $this->when($this->status === 'rejected', $this->note),
            'paid_at'      => $this->paid_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'refund_to'    => $this->when($this->type === 'refund', $this->refund_destination),
        ];
    }
}
