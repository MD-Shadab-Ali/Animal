<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerProfileResource;
use App\Models\Goat;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\NewSellerApplicationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SellerAccountController extends Controller
{
    /** Apply to sell. One application per account. */
    public function apply(Request $request): JsonResponse
    {
        if (! Setting::get('marketplace_enabled', true) || ! Setting::get('seller_applications_open', true)) {
            throw ValidationException::withMessages([
                'farm_name' => ['We are not taking new seller applications right now.'],
            ]);
        }

        // Query rather than read the relation: it may already be loaded and stale,
        // and this guard has to agree with the unique index on sellers.user_id.
        $existing = Seller::withTrashed()->where('user_id', $request->user()->id)->first();

        // Only a *live* application blocks a new one. If staff removed the old
        // one, the person is free to try again.
        if ($existing && ! $existing->trashed()) {
            throw ValidationException::withMessages([
                'farm_name' => ['You have already applied to sell.'],
            ]);
        }

        $data = $request->validate([
            'farm_name'     => ['required', 'string', 'max:255'],
            'bio'           => ['nullable', 'string', 'max:2000'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'address_line'  => ['nullable', 'string', 'max:255'],
            'area'          => ['nullable', 'string', 'max:255'],
            'city'          => ['required', 'string', 'max:255'],
            'postal_code'   => ['nullable', 'string', 'max:20'],
            'national_id'   => ['required', 'string', 'max:60'],

            // Staff cannot verify anyone without seeing the ID. The trade licence
            // is a bonus — plenty of smallholders do not have one.
            'id_document'   => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'trade_licence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'id_document.required' => 'Please attach a photo or scan of your ID.',
            'id_document.mimes'    => 'The ID must be a JPG, PNG, WEBP or PDF file.',
            'id_document.max'      => 'The ID file must be 5MB or smaller.',
            'trade_licence.mimes'  => 'The trade licence must be a JPG, PNG, WEBP or PDF file.',
            'trade_licence.max'    => 'The trade licence must be 5MB or smaller.',
            'national_id.required' => 'Please enter your national ID number.',
        ]);

        $data = array_merge($data, $this->storeDocuments($request, $existing));

        $seller = $existing
            ? $this->resubmit($existing, $data)
            : Seller::create($data + [
                'user_id' => $request->user()->id,
                'status'  => 'pending',
            ]);

        $staff = User::staffRecipients();

        if ($staff->isNotEmpty()) {
            Notification::send($staff, new NewSellerApplicationNotification($seller));
        }

        $request->user()->setRelation('seller', $seller);

        return response()->json([
            'message' => 'Thanks. We will review your application and get back to you.',
            'data'    => new SellerProfileResource($seller),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $seller = Seller::where('user_id', $request->user()->id)->first();

        if (! $seller) {
            return response()->json(['data' => null, 'code' => 'not_a_seller']);
        }

        return response()->json(['data' => new SellerProfileResource($seller)]);
    }

    public function update(Request $request): JsonResponse
    {
        $seller = Seller::where('user_id', $request->user()->id)->first();

        abort_if(! $seller, 403, 'You do not have a seller account.');

        $data = $request->validate([
            'farm_name'           => ['required', 'string', 'max:255'],
            'bio'                 => ['nullable', 'string', 'max:2000'],
            'contact_phone'       => ['required', 'string', 'max:30'],
            'contact_email'       => ['nullable', 'email', 'max:255'],
            'address_line'        => ['nullable', 'string', 'max:255'],
            'area'                => ['nullable', 'string', 'max:255'],
            'city'                => ['required', 'string', 'max:255'],
            'postal_code'         => ['nullable', 'string', 'max:20'],
            'payout_method'       => ['nullable', 'string', 'max:60'],
            'payout_account_name' => ['nullable', 'string', 'max:255'],
            'payout_account_number' => ['nullable', 'string', 'max:60'],
        ]);

        $seller->update($data);

        return response()->json([
            'message' => 'Your seller profile has been updated.',
            'data'    => new SellerProfileResource($seller->fresh()),
        ]);
    }

    /**
     * Revive an archived application with the new submission.
     *
     * The row is reused rather than replaced: `sellers.user_id` is unique, so a
     * second insert would be rejected, and past order lines still point at this
     * seller id. Everything from the previous review is cleared so staff assess
     * it fresh, and the slug is regenerated in case the farm was renamed.
     */
    private function resubmit(Seller $seller, array $data): Seller
    {
        $seller->restore();

        $seller->fill($data);

        $seller->forceFill([
            'slug'        => Seller::uniqueSlug($data['farm_name'], $seller->getKey()),
            'status'      => 'pending',
            'review_note' => null,
            'approved_at' => null,
            'approved_by' => null,
        ])->save();

        // Anything previously cleared for sale goes back through moderation, so a
        // re-approved seller cannot silently resurrect old listings unchecked.
        $seller->goats()
            ->where('approval_status', 'approved')
            ->whereNot('status', 'sold')
            ->update([
                'approval_status'  => 'pending',
                'rejection_reason' => null,
                'submitted_at'     => now(),
                'approved_at'      => null,
                'approved_by'      => null,
            ]);

        return $seller->fresh();
    }

    /** Replace the identity documents on an existing application. */
    public function updateDocuments(Request $request): JsonResponse
    {
        $seller = Seller::where('user_id', $request->user()->id)->first();

        abort_if(! $seller, 403, 'You do not have a seller account.');

        $request->validate([
            'id_document'   => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'trade_licence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'national_id'   => ['nullable', 'string', 'max:60'],
        ]);

        $changes = $this->storeDocuments($request, $seller);

        if ($request->filled('national_id')) {
            $changes['national_id'] = $request->string('national_id')->toString();
        }

        if (empty($changes)) {
            throw ValidationException::withMessages([
                'id_document' => ['Attach at least one file to update.'],
            ]);
        }

        $seller->update($changes);

        return response()->json([
            'message' => 'Documents updated. We will take another look.',
            'data'    => new SellerProfileResource($seller->fresh()),
        ]);
    }

    /**
     * Move uploaded documents onto the public disk, deleting whatever they
     * replace so old ID scans do not pile up in storage.
     */
    private function storeDocuments(Request $request, ?Seller $seller = null): array
    {
        $stored = [];

        foreach (['id_document', 'trade_licence'] as $field) {
            if (! $request->hasFile($field)) {
                continue;
            }

            if ($seller?->{$field}) {
                Storage::disk('public')->delete($seller->{$field});
            }

            $stored[$field] = $request->file($field)->store('sellers/documents', 'public');
        }

        return $stored;
    }

    /** Headline numbers for the seller dashboard. */
    public function dashboard(Request $request): JsonResponse
    {
        $seller = $request->user()->seller;

        $listings = Goat::where('seller_id', $seller->id);

        $lines = OrderItem::where('seller_id', $seller->id)
            ->whereHas('order', fn ($query) => $query->whereNot('status', 'cancelled'));

        return response()->json([
            'data' => [
                'listings' => [
                    'live'     => (clone $listings)->where('status', 'published')->where('approval_status', 'approved')->count(),
                    'pending'  => (clone $listings)->where('approval_status', 'pending')->count(),
                    'rejected' => (clone $listings)->where('approval_status', 'rejected')->count(),
                    'sold'     => (clone $listings)->where('status', 'sold')->count(),
                    'total'    => (clone $listings)->count(),
                ],
                'sales' => [
                    'orders'   => (clone $lines)->distinct('order_id')->count('order_id'),
                    'units'    => (int) (clone $lines)->sum('quantity'),
                    'revenue'  => (float) (clone $lines)->sum('line_total'),
                ],
                'earnings' => [
                    // Sold but not delivered yet, so not earned.
                    'pending'     => $seller->pending_earnings,
                    // Delivered and waiting on a payout.
                    'unpaid'      => $seller->unpaid_earnings,
                    'paid'        => (float) $seller->payouts()->where('status', 'paid')->sum('amount'),
                    // Everything actually earned across delivered orders.
                    'lifetime'    => $seller->lifetime_earnings,
                    'commission_rate' => $seller->effective_commission_rate,
                    'min_payout'  => (float) Setting::get('min_payout_amount', 0),
                ],
                'currency' => Setting::currencyCode(),
            ],
        ]);
    }
}
