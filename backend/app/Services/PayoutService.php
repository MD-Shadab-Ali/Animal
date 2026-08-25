<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payout;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayoutService
{
    /**
     * Settle everything a seller has earned on delivered orders that has not
     * already been included in a payout.
     *
     * The order lines are stamped with the payout id inside the transaction, so
     * the same earning can never be paid twice.
     */
    public function settle(Seller $seller, ?User $createdBy = null, ?string $note = null): Payout
    {
        return DB::transaction(function () use ($seller, $createdBy, $note) {
            $items = OrderItem::query()
                ->where('seller_id', $seller->id)
                ->whereNull('payout_id')
                ->whereHas('order', fn ($query) => $query->where('status', 'delivered'))
                ->lockForUpdate()
                ->get();

            // Delivery charges the seller earned by doing the delivery themselves.
            // They sit on the order rather than a line, so they settle alongside.
            $deliveries = Order::query()
                ->where('delivery_seller_id', $seller->id)
                ->where('status', 'delivered')
                ->whereNull('delivery_payout_id')
                ->where('delivery_earning', '>', 0)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty() && $deliveries->isEmpty()) {
                throw ValidationException::withMessages([
                    'payout' => ['This seller has no settled earnings waiting to be paid.'],
                ]);
            }

            $amount = round(
                (float) $items->sum('seller_earning') + (float) $deliveries->sum('delivery_earning'),
                2
            );
            $minimum = (float) Setting::get('min_payout_amount', 0);

            if ($minimum > 0 && $amount < $minimum) {
                throw ValidationException::withMessages([
                    'payout' => ['Earnings of '.number_format($amount, 2).' are below the minimum payout of '.number_format($minimum, 2).'.'],
                ]);
            }

            $payout = Payout::create([
                'reference'  => $this->reference(),
                'seller_id'  => $seller->id,
                'amount'     => $amount,
                'currency'   => Setting::currencyCode(),
                'status'     => 'pending',
                'method'     => $seller->payout_method,
                'note'       => $note,
                'created_by' => $createdBy?->id,
            ]);

            OrderItem::whereKey($items->pluck('id'))->update(['payout_id' => $payout->id]);
            Order::whereKey($deliveries->pluck('id'))->update(['delivery_payout_id' => $payout->id]);

            return $payout->load('items');
        });
    }

    public function markPaid(Payout $payout, ?string $transactionReference = null): Payout
    {
        $payout->update([
            'status'                => 'paid',
            'transaction_reference' => $transactionReference ?: $payout->transaction_reference,
            'paid_at'               => now(),
        ]);

        return $payout;
    }

    /** Releasing a failed payout puts its earnings back in the queue. */
    public function markFailed(Payout $payout, ?string $reason = null): Payout
    {
        return DB::transaction(function () use ($payout, $reason) {
            $payout->items()->update(['payout_id' => null]);
            Order::where('delivery_payout_id', $payout->id)->update(['delivery_payout_id' => null]);

            $payout->update([
                'status' => 'failed',
                'note'   => $reason ?: $payout->note,
            ]);

            return $payout;
        });
    }

    private function reference(): string
    {
        do {
            $reference = 'PO-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (Payout::where('reference', $reference)->exists());

        return $reference;
    }
}
