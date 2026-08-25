<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The seller's own view of their account, including private fields. */
class SellerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'farm_name' => $this->farm_name,
            'slug'      => $this->slug,
            'bio'       => $this->bio,
            'logo'      => $this->logo_url,
            'banner'    => $this->banner_url,

            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'address_line'  => $this->address_line,
            'area'          => $this->area,
            'city'          => $this->city,
            'postal_code'   => $this->postal_code,

            // The owner may see their own paperwork; the public resource has none of it.
            'national_id'   => $this->national_id,
            'documents' => [
                'id_document' => $this->id_document
                    ? ['url' => asset('storage/'.$this->id_document), 'name' => basename($this->id_document)]
                    : null,
                'trade_licence' => $this->trade_licence
                    ? ['url' => asset('storage/'.$this->trade_licence), 'name' => basename($this->trade_licence)]
                    : null,
            ],

            'status'      => $this->status,
            'review_note' => $this->review_note,
            'approved_at' => $this->approved_at?->toIso8601String(),

            'commission_rate' => $this->effective_commission_rate,
            'payout' => [
                'method'       => $this->payout_method,
                'bank_name'    => $this->payout_bank_name,
                'account_name' => $this->payout_account_name,
                // Only the tail of the account number is ever echoed back.
                'account_hint' => $this->payout_account_number
                    ? '••••'.substr($this->payout_account_number, -4)
                    : null,
            ],
        ];
    }
}
