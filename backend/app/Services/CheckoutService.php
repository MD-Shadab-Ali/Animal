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

        /*
         * "Buy now" on a goat means that goat, not everything the buyer happens
         * to have left in their cart from last week. Without this, clicking it
         * with three unrelated animals already in the cart ordered all four.
         */
        $items = $cart->items;

        // Cart lines win over goat ids where both are sent: a listing sold by
        // the kilo can sit on several lines, and buying the 25 kg one must not
        // drag the 37 kg one along with it.
        if (! empty($data['cart_item_ids'])) {
            $items = $items->whereIn('id', $data['cart_item_ids'])->values();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart_item_ids' => ['Those items are not in your cart any more.'],
                ]);
            }
        } elseif (! empty($data['goat_ids'])) {
            $items = $items->whereIn('goat_id', $data['goat_ids'])->values();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'goat_ids' => ['Those goats are not in your cart any more.'],
                ]);
            }
        }

        // Whether this is the whole cart decides what happens to the coupon and
        // to everything left behind.
        $wholeCart = $items->count() === $cart->items->count();

        $method = PaymentMethod::active()->where('code', $data['payment_method'])->first();

        if (! $method) {
            throw ValidationException::withMessages([
                'payment_method' => ['That payment method is not available.'],
            ]);
        }

        if (! $method->isCheckoutSelectable()) {
            throw ValidationException::withMessages([
                'payment_method' => [$method->name.' cannot be used to place an order. '
                    .'Pick how you want to pay up front — you can settle the rest in cash on delivery.'],
            ]);
        }

        // The plan has to be one this method actually offers: you cannot promise
        // to pay a wallet up front when no account has been set up for it, and a
        // method that insists on an advance cannot be deferred to the door.
        $allowed = $method->paymentPlans();

        // Nothing chosen falls back to paying at the door where the method
        // permits it, which is how every order behaved before plans existed.
        $plan = $data['payment_plan']
            ?? (in_array('on_delivery', $allowed, true) ? 'on_delivery' : $allowed[0]);

        if (! in_array($plan, $allowed, true)) {
            throw ValidationException::withMessages([
                'payment_plan' => ['That is not an option for '.$method->name.'.'],
            ]);
        }

        $zone = DeliveryZone::active()->findOrFail($data['delivery_zone_id']);

        $lowStockThreshold = (int) Setting::get('low_stock_threshold', 0);

        [$order, $lowStock] = DB::transaction(function () use (
            $user, $data, $cart, $items, $wholeCart, $zone, $method, $plan, $lowStockThreshold
        ) {
            $lowStock = [];
            $goatIds = $items->pluck('goat_id')->all();

            // Lock the goats for the duration of the transaction.
            $goats = Goat::with('seller')->whereIn('id', $goatIds)->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0.0;
            $lines    = [];

            // A listing sold by the kilo can appear on several lines at once,
            // one per weight, and they all draw on the same animals. Counted up
            // front so the stock check below sees the whole claim, not one line.
            $claimed = $items->groupBy('goat_id')->map->sum('quantity');

            foreach ($items as $item) {
                $goat = $goats[$item->goat_id] ?? null;

                if (! $goat || $goat->status !== 'published') {
                    throw ValidationException::withMessages([
                        'cart' => [($goat->name ?? 'A goat').' is no longer available. Please remove it from your cart.'],
                    ]);
                }

                if ($goat->track_stock && ($claimed[$goat->id] ?? 0) > $goat->stock) {
                    throw ValidationException::withMessages([
                        'cart' => ["Only {$goat->stock} of {$goat->name} is left."],
                    ]);
                }

                // Re-checked here and not just in the cart: the seller may have
                // narrowed the weights they supply while this cart sat waiting.
                if ($goat->is_weight_priced && ! $goat->isWeightAllowed((float) $item->weight_kg)) {
                    throw ValidationException::withMessages([
                        'cart' => [$goat->name.' is no longer sold at '.(float) $item->weight_kg
                            .' kg. Please pick a weight between '.$goat->lightest_weight
                            .' kg and '.$goat->heaviest_weight.' kg.'],
                    ]);
                }

                $unitPrice = $goat->is_weight_priced
                    ? $goat->priceForWeight((float) $item->weight_kg)
                    : $goat->effective_price;

                $lineTotal = round($unitPrice * $item->quantity, 2);
                $subtotal += $lineTotal;

                $lines[] = [
                    'goat'         => $goat,
                    'quantity'     => $item->quantity,
                    'weight_kg'    => $goat->is_weight_priced ? (float) $item->weight_kg : null,
                    'price_per_kg' => $goat->is_weight_priced ? (float) $goat->price_per_kg : null,
                    'unit_price'   => $unitPrice,
                    'line_total'   => $lineTotal,
                ];
            }

            $minimum = (float) Setting::get('min_order_amount', 0);

            if ($minimum > 0 && $subtotal < $minimum) {
                throw ValidationException::withMessages([
                    'cart' => ['The minimum order amount is '.number_format($minimum).'.'],
                ]);
            }

            // A coupon is applied to the cart, so it is redeemed by checking
            // the cart out. Letting it discount a single-item purchase would
            // spend a whole-basket voucher on one animal.
            $coupon   = $wholeCart ? $cart->coupon : null;
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
                // Kept with the order so the promise survives a zone being
                // edited or removed later.
                'delivery_estimate' => $zone->estimated_time,
                'total'           => $total,
                'currency'        => Setting::currencyCode(),

                'payment_method'   => $method->code,
                'payment_plan'     => $plan,
                'payment_status'   => 'unpaid',
                'paid_amount'      => 0,
                // What has to be in before the goat moves. Null means nothing
                // is wanted until it arrives at the door.
                'advance_required' => match ($plan) {
                    'full'    => $total,
                    'advance' => $method->advanceFor($total),
                    default   => null,
                },
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
                    // Kept on the line so the order still reads correctly after
                    // the seller changes their rate.
                    'weight_kg'      => $line['weight_kg'],
                    'price_per_kg'   => $line['price_per_kg'],
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

            // Only what was actually bought leaves the cart.
            $cart->items()->whereKey($items->pluck('id'))->delete();

            if ($cart->items()->count() === 0) {
                $cart->update(['coupon_id' => null]);
            }

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
