<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentLedgerResource;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The buyer's own money, in one place.
 *
 * Every payment was already recorded, but only ever readable one order at a
 * time -- so "what have I paid this shop, and when" meant opening every order
 * in turn and adding up by hand.
 */
class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /*
         * Scoped through the order rather than through payments.user_id. A row
         * staff record on the buyer's behalf carries whoever keyed it in, and
         * a gateway row carries nobody; money against your own order is yours
         * to see however it came to be written down.
         */
        $mine = fn (Builder $query) => $query->where('user_id', $request->user()->id);

        $payments = Payment::with('order.items')
            ->whereHas('order', $mine)
            // When the money moved, not when the row was typed. A payment
            // staff enter days later belongs where it happened; one that was
            // never dated falls back to the row itself so it cannot vanish
            // to the bottom of the list.
            ->orderByRaw('COALESCE(paid_at, created_at) DESC')
            ->orderByDesc('id')
            ->paginate(15);

        // Only settled money counts: a claim staff have not checked yet, or
        // one they rejected, is not something the buyer has actually paid.
        $settled = Payment::whereHas('order', $mine)->confirmed();

        return PaymentLedgerResource::collection($payments)->additional([
            'summary' => [
                'paid' => round((float) (clone $settled)->payments()->sum('amount'), 2),
                'refunded' => round((float) (clone $settled)->refunds()->sum('amount'), 2),
            ],
        ]);
    }
}
