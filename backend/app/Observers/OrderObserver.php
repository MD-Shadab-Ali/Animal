<?php

namespace App\Observers;

use App\Models\Goat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Notifications\OrderStatusChangedNotification;

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

        // Cash on delivery is settled the moment the goat is handed over.
        if ($to === 'delivered' && $order->payment_method === 'cod' && $order->payment_status === 'unpaid') {
            $order->payment_status = 'paid';
            $order->paid_amount = $order->total;
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
