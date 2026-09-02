<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\CheckoutService;
use App\Support\Pickup;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    /** Delivery zones and payment methods the admin currently has switched on. */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'delivery_zones' => DeliveryZone::active()
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (DeliveryZone $zone) => [
                        'id' => $zone->id,
                        'name' => $zone->name,
                        'description' => $zone->description,
                        'charge' => (float) $zone->charge,
                        'free_above' => $zone->free_above !== null ? (float) $zone->free_above : null,
                        'estimated_time' => $zone->estimated_time,
                        // The buyer comes to us for this one, so the checkout
                        // asks for a time instead of an address.
                        'is_pickup' => $zone->isPickup(),
                    ]),

                // Only the shape of the slot picker -- the farm address and the
                // hours it hands goats over. Cheap enough to always send: it is
                // a handful of settings, and the checkout needs it the instant
                // the buyer picks collection.
                'pickup' => Pickup::config(),

                'payment_methods' => PaymentMethod::active()
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (PaymentMethod $method) => [
                        'code' => $method->code,
                        'name' => $method->name,
                        'instructions' => $method->instructions,
                        'logo' => $method->logo_url,
                        'requires_advance' => $method->requires_advance,
                        'advance_amount' => $method->advance_amount !== null ? (float) $method->advance_amount : null,
                        // Whether that number is rupees or a percentage.
                        'advance_type' => $method->advance_type,

                        // Which of "all of it / part of it / nothing yet" this
                        // method allows, and where the money would go.
                        'plans' => $method->paymentPlans(),
                        'payee' => $method->payeeDetails(),

                        // Shown but not choosable: cash on delivery settles the
                        // balance at the door, it does not start an order.
                        'selectable' => $method->isCheckoutSelectable(),
                        'unavailable_reason' => $method->checkoutUnavailableReason(),
                    ]),

                'payment_plans' => Order::PAYMENT_PLANS,
                // The share of the total an advance comes to, so the storefront
                // can show the figure before the order exists.
                'advance_percent' => (float) Setting::get('advance_percent', 30),
                'min_order_amount' => (float) Setting::get('min_order_amount', 0),
            ],
        ]);
    }

    public function store(Request $request, CheckoutService $checkout): JsonResponse
    {
        /*
         * Collection changes what the order even needs. There is no address to
         * deliver to, and there is a time to be at the gate -- so the rules are
         * built from the zone rather than asking a buyer collecting in person
         * for a postal code we will never use.
         */
        $zone = DeliveryZone::find($request->input('delivery_zone_id'));
        $isPickup = (bool) $zone?->isPickup();

        // Still validated when present: a stale address left in the form by a
        // buyer who switched to collection must not ride along unchecked.
        $addressRule = $isPickup ? 'nullable' : 'required';

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['required', 'email', 'max:255'],
            'address_line' => [$addressRule, 'string', 'max:255'],
            'area' => [$addressRule, 'string', 'max:255'],
            'city' => [$addressRule, 'string', 'max:255'],
            'postal_code' => [$addressRule, 'string', 'max:20'],
            'order_notes' => ['nullable', 'string', 'max:1000'],

            /*
             * Checked against the slots we actually offered, not merely parsed
             * as a date. The browser draws the picker; this is the side that
             * has to have somebody at the gate when the buyer arrives, so an
             * hour nobody works is refused here even if it was posted directly.
             */
            'pickup_at' => [
                Rule::requiredIf($isPickup),
                'nullable',
                'date',
                function (string $attribute, $value, Closure $fail) use ($isPickup) {
                    if (! $isPickup || $value === null) {
                        return;
                    }

                    $problem = Pickup::problemWith(CarbonImmutable::parse($value));

                    if ($problem !== null) {
                        $fail($problem);
                    }
                },
            ],
            'delivery_zone_id' => ['required', 'exists:delivery_zones,id'],
            'payment_method' => ['required', 'string', 'exists:payment_methods,code'],

            // Absent means the whole cart, which is the normal path. Present
            // means "buy just these", as the goat page's Buy now does.
            'goat_ids' => ['nullable', 'array', 'min:1'],
            'goat_ids.*' => ['integer'],
            // Preferred over goat_ids: one listing sold by the kilo can be on
            // several cart lines at once, and "buy now" means the one on screen.
            'cart_item_ids' => ['nullable', 'array', 'min:1'],
            'cart_item_ids.*' => ['integer'],
            // Optional: left out, the method's own default applies, which for
            // cash on delivery is exactly what it always was.
            'payment_plan' => ['nullable', 'string', Rule::in(array_keys(Order::PAYMENT_PLANS))],
            'save_address' => ['nullable', 'boolean'],
        ]);

        $order = $checkout->place($request->user(), $data);

        // Nothing to save when the buyer is collecting: there is no address,
        // and writing a blank one into their address book would put a useless
        // entry in front of them at every future checkout.
        if ($request->boolean('save_address') && ! $isPickup) {
            $request->user()->addresses()->create([
                'label' => 'Delivery address',
                'full_name' => $data['customer_name'],
                'phone' => $data['customer_phone'],
                'address_line' => $data['address_line'],
                'area' => $data['area'] ?? null,
                'city' => $data['city'],
                'postal_code' => $data['postal_code'] ?? null,
                'is_default' => $request->user()->addresses()->count() === 0,
            ]);
        }

        return response()->json([
            'message' => 'Order placed. We will call you to confirm.',
            'data' => new OrderResource($order),
        ], 201);
    }
}
