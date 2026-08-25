<?php

namespace App\Services;

use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\OrderPlacedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates an order on behalf of a customer — for phone or walk-in sales taken
 * by staff. It gives the same guarantees as the storefront checkout: goat rows
 * are locked, prices come from the database, and stock is decremented.
 */
class ManualOrderService
{
    public function create(array $data, ?User $placedBy = null): Order
    {
        $items = collect($data['items'] ?? [])
            ->filter(fn ($row) => filled($row['goat_id'] ?? null))
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['Add at least one goat to the order.'],
            ]);
        }

        $zone = DeliveryZone::find($data['delivery_zone_id'] ?? null);

        $order = DB::transaction(function () use ($data, $items, $zone) {
            $goats = Goat::with('seller')
                ->whereIn('id', $items->pluck('goat_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0.0;
            $lines = [];

            foreach ($items as $row) {
                $goat = $goats[$row['goat_id']] ?? null;

                if (! $goat) {
                    throw ValidationException::withMessages([
                        'items' => ['One of the selected goats no longer exists.'],
                    ]);
                }

                $quantity = max(1, (int) ($row['quantity'] ?? 1));

                if ($goat->track_stock && $quantity > $goat->stock) {
                    throw ValidationException::withMessages([
                        'items' => ["Only {$goat->stock} of {$goat->name} is in stock."],
                    ]);
                }

                // Staff may override the price for a haggled sale.
                $unitPrice = filled($row['unit_price'] ?? null)
                    ? (float) $row['unit_price']
                    : $goat->effective_price;

                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $lines[] = compact('goat', 'quantity', 'unitPrice', 'lineTotal');
            }

            $discount = round((float) ($data['discount'] ?? 0), 2);
            $delivery = array_key_exists('delivery_charge', $data) && filled($data['delivery_charge'])
                ? round((float) $data['delivery_charge'], 2)
                : ($zone?->chargeFor($subtotal - $discount) ?? 0.0);

            $total = round($subtotal - $discount + $delivery, 2);

            $order = Order::create([
                'order_number'     => $this->orderNumber(),
                'user_id'          => $data['user_id'],
                'delivery_zone_id' => $zone?->id,

                'customer_name'    => $data['customer_name'],
                'customer_phone'   => $data['customer_phone'],
                'customer_email'   => $data['customer_email'] ?? null,
                'address_line'     => $data['address_line'],
                'area'             => $data['area'] ?? null,
                'city'             => $data['city'],
                'postal_code'      => $data['postal_code'] ?? null,
                'order_notes'      => $data['order_notes'] ?? null,
                'admin_note'       => $data['admin_note'] ?? null,

                'subtotal'        => $subtotal,
                'discount'        => $discount,
                'delivery_charge' => $delivery,
                'total'           => $total,
                'currency'        => Setting::currencyCode(),

                'payment_method' => $data['payment_method'] ?? 'cod',
                'payment_status' => 'unpaid',
                'paid_amount'    => 0,
                'status'         => $data['status'] ?? 'pending',
            ]);

            foreach ($lines as $line) {
                $goat = $line['goat'];

                $seller = $goat->seller;
                $commissionRate = $seller ? $seller->effective_commission_rate : 0.0;
                $commissionAmount = round($line['lineTotal'] * ($commissionRate / 100), 2);

                OrderItem::create([
                    'order_id'       => $order->id,
                    'goat_id'        => $goat->id,
                    'goat_name'      => $goat->name,
                    'goat_sku'       => $goat->sku,
                    'goat_thumbnail' => $goat->thumbnail,
                    'unit_price'     => $line['unitPrice'],
                    'quantity'       => $line['quantity'],
                    'line_total'     => $line['lineTotal'],

                    'seller_id'         => $seller?->id,
                    'seller_name'       => $seller?->farm_name,
                    'commission_rate'   => $commissionRate,
                    'commission_amount' => $commissionAmount,
                    'seller_earning'    => $seller ? round($line['lineTotal'] - $commissionAmount, 2) : 0,
                ]);

                if ($goat->track_stock) {
                    $goat->decrement('stock', $line['quantity']);
                    $goat->refresh();

                    if ($goat->stock <= 0) {
                        $goat->update(['status' => 'sold']);
                    }
                }
            }

            // One seller supplying the whole order also delivers it, so the
            // delivery charge is theirs. Commission is not taken on delivery.
            $order->load('items');
            $soleSeller = $order->soleSellerId();

            if ($soleSeller && $order->delivery_charge > 0) {
                $order->update([
                    'delivery_seller_id' => $soleSeller,
                    'delivery_earning'   => $order->delivery_charge,
                ]);
            }

            return $order->load('items', 'deliveryZone');
        });

        // Staff-entered orders still confirm to the customer, if they have an email.
        $order->user?->notify(new OrderPlacedNotification($order));

        return $order;
    }

    private function orderNumber(): string
    {
        do {
            $number = 'GH-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (Order::withTrashed()->where('order_number', $number)->exists());

        return $number;
    }
}
