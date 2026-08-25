<?php

namespace App\Observers;

use App\Models\Goat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Seller;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\SellerOrderCancelledNotification;
use Illuminate\Validation\ValidationException;

class OrderObserver
{
    public function created(Order $order): void
    {
        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'user_id'     => auth()->id(),
            'from_status' => null,
            'to_status'   => $order->status,
            'note'        => 'Order placed',
        ]);
    }

    public function updating(Order $order): void
    {
        if (! $order->isDirty('status')) {
            return;
        }

        $to = $order->status;

        if ($to === 'delivered' && ! $order->delivered_at) {
            $order->delivered_at = now();
        }

        if ($to === 'cancelled' && ! $order->cancelled_at) {
            $order->cancelled_at = now();
        }

        // The last gate before an order closes, and deliberately at the model
        // rather than in one screen: delivered means paid for, whoever is
        // asking. Cash on delivery is no exception - the rider's cash is
        // recorded as a payment, and that is what closes the order.
        if ($to === 'delivered' && ! $order->canBeDelivered()) {
            throw ValidationException::withMessages([
                'status' => ['This order cannot be marked delivered until it is paid for. '
                    .'Record the payment first and it will close itself.'],
            ]);
        }
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $previous = (string) $order->getOriginal('status');

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'user_id'     => auth()->id(),
            'from_status' => $previous,
            'to_status'   => $order->status,
        ]);

        // A cancelled order releases its goats back into the shop, and no seller
        // should be left with a line still asking them to prepare an animal.
        if ($order->status === 'cancelled') {
            $this->restock($order);

            $order->items()
                ->whereNot('fulfilment_status', 'cancelled')
                ->update([
                    'fulfilment_status'     => 'cancelled',
                    'fulfilment_updated_at' => now(),
                ]);

            $this->tellSellers($order);
        }

        // Keep the customer informed at every step.
        $order->user?->notify(new OrderStatusChangedNotification($order, $previous));

        $this->carryStatusDownToLines($order);
    }

    /**
     * When the order moves, drag any lagging line up with it.
     *
     * Forward only, so a supplier who is already further ahead is never pulled
     * back. Line writes do not roll the order back up, so this cannot loop.
     */
    private function carryStatusDownToLines(Order $order): void
    {
        if ($order->status === 'cancelled') {
            return;
        }

        $target = match ($order->status) {
            'confirmed', 'processing'   => 'preparing',
            'out_for_delivery'          => 'handed_over',
            'delivered'                 => 'handed_over',
            default                     => null,
        };

        if (! $target) {
            return;
        }

        $targetRank = array_search($target, OrderItem::SELLER_FLOW, true);

        $behind = array_filter(
            OrderItem::SELLER_FLOW,
            fn (string $status) => array_search($status, OrderItem::SELLER_FLOW, true) < $targetRank
        );

        $order->items()
            ->whereIn('fulfilment_status', $behind)
            ->update([
                'fulfilment_status'     => $target,
                'fulfilment_updated_at' => now(),
            ]);
    }

    /**
     * Anyone who was preparing a goat for this order needs to hear about it.
     *
     * A buyer may cancel right up to the handover, so this can arrive while the
     * animal is penned and ready to load.
     */
    private function tellSellers(Order $order): void
    {
        $lines = $order->items()->whereNotNull('seller_id')->get();

        if ($lines->isEmpty()) {
            return;
        }

        Seller::with('user')
            ->whereIn('id', $lines->pluck('seller_id')->unique())
            ->get()
            ->each(fn (Seller $seller) => $seller->user?->notify(
                new SellerOrderCancelledNotification($order, $lines->where('seller_id', $seller->id))
            ));
    }

    private function restock(Order $order): void
    {
        foreach ($order->items as $item) {
            if (! $item->goat_id) {
                continue;
            }

            Goat::where('id', $item->goat_id)
                ->where('track_stock', true)
                ->increment('stock', $item->quantity);

            Goat::where('id', $item->goat_id)
                ->where('status', 'sold')
                ->update(['status' => 'published']);
        }
    }
}
