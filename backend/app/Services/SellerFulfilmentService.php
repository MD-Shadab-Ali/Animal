<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\SellerReadyForCollectionNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Moves a single order line through its fulfilment states on behalf of the
 * seller who owns it. Deliberately scoped to one line: an order can contain
 * goats from several sellers, so no seller may touch the order as a whole.
 */
class SellerFulfilmentService
{
    /**
     * Move a whole order forward on behalf of the seller who supplied all of it.
     *
     * Only orders sourced entirely from one seller qualify. Anything with house
     * stock or a second seller on it stays with staff, because no single seller
     * can speak for the whole delivery.
     */
    public function advanceOrder(Seller $seller, Order $order, string $status, ?string $note = null): Order
    {
        $order->loadMissing('items');

        if (! $order->isManagedBy($seller)) {
            throw ValidationException::withMessages([
                'order' => ['This order is handled by our team, not by you.'],
            ]);
        }

        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'status' => ['This order has been cancelled.'],
            ]);
        }

        // Cancelling is an intervention, not a step: sellers ask staff instead.
        if (! in_array($status, Order::FLOW, true)) {
            throw ValidationException::withMessages([
                'status' => ['That is not a status you can set. Contact us if the order needs cancelling.'],
            ]);
        }

        if (! $order->canAdvanceTo($status)) {
            throw ValidationException::withMessages([
                'status' => ['You can only move this forward from "'.$order->status_label.'".'],
            ]);
        }

        // Caught here as well as in the model so the seller gets an answer that
        // makes sense on their own screen rather than a bare validation error.
        if ($status === 'delivered' && ! $order->canBeDelivered()) {
            throw ValidationException::withMessages([
                'status' => ['We have not received payment for this order yet. '
                    .'It closes itself once the buyer has paid.'],
            ]);
        }

        $order->update([
            'status'     => $status,
            'admin_note' => $note ? trim($order->admin_note."
".'Seller: '.$note) : $order->admin_note,
        ]);

        // Keep the seller's own line in step so the two never disagree.
        $lineStatus = Order::lineStatusFor($status);

        if ($lineStatus) {
            $order->items()
                ->where('seller_id', $seller->id)
                ->whereNot('fulfilment_status', 'cancelled')
                ->update([
                    'fulfilment_status'     => $lineStatus,
                    'fulfilment_updated_at' => now(),
                ]);
        }

        return $order->fresh(['items']);
    }

    public function advance(Seller $seller, OrderItem $item, string $status, ?string $note = null): OrderItem
    {
        if ($item->seller_id !== $seller->id) {
            throw ValidationException::withMessages([
                'item' => ['That sale does not belong to you.'],
            ]);
        }

        $order = $item->order;

        if (! $order || $order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'status' => ['This order has been cancelled, so it can no longer be updated.'],
            ]);
        }

        if (! array_key_exists($status, OrderItem::FULFILMENT_STATUSES) || $status === 'cancelled') {
            throw ValidationException::withMessages([
                'status' => ['That is not a status you can set.'],
            ]);
        }

        if (! $item->canAdvanceTo($status)) {
            throw ValidationException::withMessages([
                'status' => ['You can only move this forward from "'.$item->fulfilment_label.'".'],
            ]);
        }

        $item->update([
            'fulfilment_status'     => $status,
            'fulfilment_note'       => $note ?: $item->fulfilment_note,
            'fulfilment_updated_at' => now(),
        ]);

        // Staff need to know when there is something to collect.
        if ($status === 'ready') {
            $staff = User::staffRecipients();

            if ($staff->isNotEmpty()) {
                Notification::send($staff, new SellerReadyForCollectionNotification($item->fresh()));
            }
        }

        // Pull the order status up so the buyer sees the progress too.
        $this->syncOrderStatusFromLines($order);

        return $item->fresh();
    }
    /**
     * Pull an order's status up to match its lines.
     *
     * Applies to every order, seller-run included. A seller can reach the same
     * place through the order control or by advancing their line, and both must
     * land the buyer in the same state. It never rewinds, so a manual override
     * by staff or a seller still stands.
     */
    public function syncOrderStatusFromLines(Order $order): Order
    {
        $order->refresh()->loadMissing('items');

        if ($order->status === 'cancelled') {
            return $order;
        }

        $live = $order->items->where('fulfilment_status', '!=', 'cancelled');

        if ($live->isEmpty()) {
            return $order;
        }

        // The order can only be as far along as its least advanced line.
        $ranks = $live->map(fn (OrderItem $item) => array_search(
            $item->fulfilment_status,
            OrderItem::SELLER_FLOW,
            true
        ))->filter(fn ($rank) => $rank !== false);

        if ($ranks->count() !== $live->count()) {
            return $order;
        }

        $slowest = OrderItem::SELLER_FLOW[$ranks->min()];

        $target = match ($slowest) {
            'preparing', 'ready' => 'processing',
            'handed_over'        => 'out_for_delivery',
            default              => null,
        };

        if ($target && $order->canAdvanceTo($target)) {
            $order->update(['status' => $target]);
        }

        return $order->fresh();
    }


}
