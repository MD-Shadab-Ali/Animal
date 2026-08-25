<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\DeliveryZone;
use App\Models\Goat;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\SellerSaleNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    /**
     * Turn the customer's cart into an order.
     *
     * Everything that touches money or stock happens inside one transaction,
     * with the goat rows locked so two buyers cannot claim the same animal.
     */
    public function place(User $user, array $data): Order
    {
        $cart = Cart::with('items.goat', 'coupon')
            ->where('user_id', $user->id)
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Your cart is empty.']]);
        }

        $method = PaymentMethod::active()->where('code', $data['payment_method'])->first();

        if (! $method) {
            throw ValidationException::withMessages([
                'payment_method' => ['That payment method is not available.'],
            ]);
        }

        $zone = DeliveryZone::active()->findOrFail($data['delivery_zone_id']);

        $lowStockThreshold = (int) Setting::get('low_stock_threshold', 0);

        [$order, $lowStock] = DB::transaction(function () use ($user, $data, $cart, $zone, $method, $lowStockThreshold) {
            $lowStock = [];
            $goatIds = $cart->items->pluck('goat_id')->all();

            // Lock the goats for the duration of the transaction.
            $goats = Goat::with('seller')->whereIn('id', $goatIds)->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0.0;
            $lines    = [];

            foreach ($cart->items as $item) {
                $goat = $goats[$item->goat_id] ?? null;

                if (! $goat || $goat->status !== 'published') {
                    throw ValidationException::withMessages([
                        'cart' => [($goat->name ?? 'A goat').' is no longer available. Please remove it from your cart.'],
                    ]);
                }

                if ($goat->track_stock && $item->quantity > $goat->stock) {
                    throw ValidationException::withMessages([
                        'cart' => ["Only {$goat->stock} of {$goat->name} is left."],
                    ]);
                }

                $unitPrice = $goat->effective_price;
                $lineTotal = round($unitPrice * $item->quantity, 2);
                $subtotal += $lineTotal;

                $lines[] = [
                    'goat'       => $goat,
                    'quantity'   => $item->quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            $minimum = (float) Setting::get('min_order_amount', 0);

            if ($minimum > 0 && $subtotal < $minimum) {
                throw ValidationException::withMessages([
                    'cart' => ['The minimum order amount is '.number_format($minimum).'.'],
                ]);
            }

            $coupon   = $cart->coupon;
            $discount = 0.0;

            if ($coupon && $coupon->isRedeemable($subtotal, $user->id)) {
                $discount = $coupon->discountFor($subtotal);
            }

            $deliveryCharge = $zone->chargeFor($subtotal - $discount);
            $total          = round($subtotal - $discount + $deliveryCharge, 2);

            $order = Order::create([
                'order_number'     => $this->orderNumber(),
                'user_id'          => $user->id,
                'delivery_zone_id' => $zone->id,
                'coupon_id'        => $discount > 0 ? $coupon?->id : null,

                'customer_name'    => $data['customer_name'],
                'customer_phone'   => $data['customer_phone'],
                'customer_email'   => $data['customer_email'] ?? $user->email,
                'address_line'     => $data['address_line'],
                'area'             => $data['area'] ?? null,
                'city'             => $data['city'],
                'postal_code'      => $data['postal_code'] ?? null,
                'order_notes'      => $data['order_notes'] ?? null,

                'subtotal'        => $subtotal,
                'discount'        => $discount,
                'delivery_charge' => $deliveryCharge,
                'total'           => $total,
                'currency'        => Setting::currencyCode(),

                'payment_method'   => $method->code,
                'payment_status'   => 'unpaid',
                'paid_amount'      => 0,
                // Recorded so staff know what to collect up front; null means the
                // whole amount is due on delivery.
                'advance_required' => $method->requires_advance
                    ? min((float) ($method->advance_amount ?? 0), $total)
                    : null,
                'status'           => 'pending',
            ]);

            foreach ($lines as $line) {
                $goat = $line['goat'];

                // Commission is frozen onto the line: rates change, settled sales must not.
                $seller = $goat->seller;
                $commissionRate = $seller ? $seller->effective_commission_rate : 0.0;
                $commissionAmount = round($line['line_total'] * ($commissionRate / 100), 2);

                OrderItem::create([
                    'order_id'       => $order->id,
                    'goat_id'        => $goat->id,
                    'goat_name'      => $goat->name,
                    'goat_sku'       => $goat->sku,
                    'goat_thumbnail' => $goat->thumbnail,
                    'unit_price'     => $line['unit_price'],
                    'quantity'       => $line['quantity'],
                    'line_total'     => $line['line_total'],

                    'seller_id'         => $seller?->id,
                    'seller_name'       => $seller?->farm_name,
                    'commission_rate'   => $commissionRate,
                    'commission_amount' => $commissionAmount,
                    'seller_earning'    => $seller ? round($line['line_total'] - $commissionAmount, 2) : 0,
                ]);

                if ($goat->track_stock) {
                    $goat->decrement('stock', $line['quantity']);
                    $goat->refresh();

                    if ($goat->stock <= 0) {
                        $goat->update(['status' => 'sold']);
                    }

                    if ($goat->stock <= $lowStockThreshold) {
                        $lowStock[] = $goat;
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

            if ($discount > 0 && $coupon) {
                $coupon->increment('used_count');
            }

            $cart->items()->delete();
            $cart->update(['coupon_id' => null]);

            return [$order->load('items', 'deliveryZone'), $lowStock];
        });

        // Notifications go out only once the transaction has committed, so a
        // rolled-back order can never trigger a confirmation email.
        $this->announce($order, $lowStock);

        return $order;
    }

    /** Tell the customer and the farm about a freshly placed order. */
    private function announce(Order $order, array $lowStock): void
    {
        $customer = $order->user;

        if ($customer) {
            $customer->notify(new OrderPlacedNotification($order));
        }

        $staff = User::staffRecipients();

        if ($staff->isNotEmpty()) {
            Notification::send($staff, new NewOrderNotification($order));

            foreach ($lowStock as $goat) {
                Notification::send($staff, new LowStockNotification($goat));
            }
        }

        $this->notifySellers($order);
    }

    /** Each seller hears about their own lines, and only their own. */
    private function notifySellers(Order $order): void
    {
        $order->loadMissing('items.seller.user');

        $order->items
            ->filter(fn ($item) => $item->seller?->user !== null)
            ->groupBy('seller_id')
            ->each(function ($items) use ($order) {
                $items->first()->seller->user->notify(
                    new SellerSaleNotification($order, $items)
                );
            });
    }

    private function orderNumber(): string
    {
        do {
            $number = 'GH-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}
