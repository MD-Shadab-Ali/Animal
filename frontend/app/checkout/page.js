'use client';

import { AnimatePresence, m } from 'motion/react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useRef, useState } from 'react';
import toast from 'react-hot-toast';
import CartSummary from '@/components/cart/CartSummary';
import CheckoutFields from '@/components/checkout/CheckoutFields';
import CheckoutReview from '@/components/checkout/CheckoutReview';
import CheckoutSteps from '@/components/checkout/CheckoutSteps';
import { useAuth } from '@/context/AuthContext';
import { useCart } from '@/context/CartContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { isGatewayMethod, payThroughGateway } from '@/lib/gateway';
import { formatDate, formatMoney } from '@/lib/format';
import { TRANSITION, stepPane } from '@/lib/motion';

function CheckoutInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const settings = useSettings();
  const { user, token, isAuthenticated, loading: authLoading } = useAuth();
  const { cart, refresh } = useCart();

  const [options, setOptions] = useState(null);
  const [prefilled, setPrefilled] = useState(false);
  const [errors, setErrors] = useState({});
  const [placing, setPlacing] = useState(false);
  const [step, setStep] = useState(1);
  const [forward, setForward] = useState(true);

  /*
   * The collection slot, held as a day and an hour rather than one datetime.
   * Two inputs is what a person picking a time actually does, and keeping them
   * apart means a half-answered slot -- a date with no time yet -- stays
   * half-answered instead of collapsing into a wrong moment.
   */
  const [pickupDate, setPickupDate] = useState('');
  const [pickupTime, setPickupTime] = useState('');

  const [form, setForm] = useState({
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    address_line: '',
    area: '',
    city: '',
    postal_code: '',
    order_notes: '',
    delivery_zone_id: '',
    payment_method: '',
    payment_plan: '',
    save_address: true,
  });

  /*
   * `user` is a different object every time it is fetched, and AuthContext
   * refetches it whenever this tab regains focus. Depending on it directly
   * re-ran the bootstrap below -- and with it the address prefill -- while the
   * buyer was part-way through the form: switch to another window, come back,
   * and everything typed since the page loaded had been quietly replaced by the
   * saved address. Held in a ref, the bootstrap can depend on the token alone.
   */
  const userRef = useRef(user);

  useEffect(() => {
    userRef.current = user;
  });

  // Prefill is a convenience, and a convenience never overwrites a person's
  // own typing. Both of these fire once and then stop.
  const addressApplied = useRef(false);
  const identityFilled = useRef(false);

  // Declared before the effect below, which calls it once addresses load.
  const applyAddress = useCallback((address) => {
    setForm((current) => ({
      ...current,
      // Filled, never replaced. A saved address with no area must not blank an
      // area the buyer has already typed -- which is exactly what it did, and
      // the server then refused the order for a missing area the buyer could
      // see themselves having entered.
      customer_name: current.customer_name || address.full_name || '',
      customer_phone: current.customer_phone || address.phone || '',
      address_line: current.address_line || address.address_line || '',
      area: current.area || address.area || '',
      city: current.city || address.city || '',
      postal_code: current.postal_code || address.postal_code || '',
      save_address: false,
    }));
  }, []);

  // The account's own name and contact details, once, as soon as they are
  // known -- they arrive after the first render, which is why this is not
  // folded into the fetch below.
  useEffect(() => {
    if (!user || identityFilled.current) return;

    identityFilled.current = true;

    setForm((current) => ({
      ...current,
      customer_name: current.customer_name || user.name || '',
      customer_phone: current.customer_phone || user.phone || '',
      customer_email: current.customer_email || user.email || '',
    }));
  }, [user]);

  // Pull the delivery zones and payment methods the admin has enabled.
  useEffect(() => {
    if (!token) return;

    Promise.all([
      apiFetch('/checkout/options', { token }),
      apiFetch('/addresses', { token }),
    ])
      .then(([optionsResponse, addressResponse]) => {
        const data = optionsResponse.data;
        setOptions(data);

        setForm((current) => ({
          ...current,
          customer_name: current.customer_name || userRef.current?.name || '',
          customer_phone: current.customer_phone || userRef.current?.phone || '',
          customer_email: current.customer_email || userRef.current?.email || '',
          delivery_zone_id: current.delivery_zone_id || data.delivery_zones?.[0]?.id || '',
          // Default to the first method that can actually place an order —
          // cash on delivery sits at the top of the list but only settles it.
          payment_method: current.payment_method
            || (data.payment_methods || []).find((method) => method.selectable !== false)?.code
            || '',
        }));

        /*
         * The address book is read, never shown. Whatever the buyer marked as
         * default is the answer; failing that the only one they have, since an
         * account with a single unmarked address still means that address.
         */
        const saved = addressResponse.data || [];
        const preferred = saved.find((address) => address.is_default) || saved[0];

        if (preferred && !addressApplied.current) {
          addressApplied.current = true;
          applyAddress(preferred);
          setPrefilled(true);
        }
      })
      .catch(() => toast.error('Could not load checkout options.'));
    // Deliberately not `user`: see the ref above. This is a one-time bootstrap
    // for a session, and re-running it mid-form is what broke the address.
  }, [token, applyAddress]);

  const update = (key) => (event) => {
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    setForm((current) => ({ ...current, [key]: value }));

    // A field's error answers "this is why you cannot go on". The moment the
    // buyer edits that field the answer is stale, and leaving it up reads as
    // "still wrong" while they are in the middle of putting it right. Cleared
    // per field, so the other outstanding ones stay marked.
    setErrors((current) => {
      if (!current[key]) return current;

      const next = { ...current };
      delete next[key];

      return next;
    });
  };

  const selectedZone = options?.delivery_zones?.find(
    (zone) => String(zone.id) === String(form.delivery_zone_id)
  );

  /*
   * "Buy now" on a goat page arrives as ?buy=<id> and means that goat alone.
   * Everything else in the cart stays where it is — ordering it too was the
   * bug this exists to prevent.
   */
  const buyOnly = searchParams.get('buy');
  // A listing sold by the kilo can be in the cart at several weights, so the
  // goat id alone no longer says which line was bought.
  const buyWeight = searchParams.get('kg');

  const lineItems = buyOnly
    ? (cart?.items || []).filter((item) => String(item.goat?.id) === String(buyOnly)
      && (buyWeight === null || String(item.weight_kg) === String(buyWeight)))
    : (cart?.items || []);

  // A coupon belongs to the whole basket, so a single-item purchase is priced
  // straight off its own lines and the coupon box is hidden.
  const subtotalAfterDiscount = buyOnly
    ? lineItems.reduce((sum, item) => sum + Number(item.line_total || 0), 0)
    : Number(cart?.totals?.total || 0);

  const deliveryCharge = selectedZone
    ? (selectedZone.free_above !== null && subtotalAfterDiscount >= selectedZone.free_above ? 0 : selectedZone.charge)
    : null;

  const chosenMethod = options?.payment_methods?.find(
    (method) => method.code === form.payment_method
  );

  // Derived, not synced: if the chosen method turns out to be delivery-only
  // (an admin can flip that at any time) fall back to the first usable one.
  const selectedMethod = chosenMethod?.selectable === false
    ? options?.payment_methods?.find((method) => method.selectable !== false)
    : chosenMethod;

  // What the order will come to, which is what an advance is a share of.
  const orderTotal = subtotalAfterDiscount + Number(deliveryCharge || 0);

  // A plan only means something for the method it belongs to. Derive it rather
  // than syncing state, so switching method can never leave a stale choice.
  const plans = selectedMethod?.plans || [];
  const paymentPlan = plans.includes(form.payment_plan) ? form.payment_plan : (plans[0] || '');

  /*
   * Which step owns which field. The server validates the whole order at once,
   * so when it rejects something the buyer may be three steps away from the
   * input that caused it -- this is what sends them back to it.
   */
  const STEP_OF_FIELD = {
    customer_name: 1, customer_phone: 1, customer_email: 1, address_line: 1,
    area: 1, city: 1, postal_code: 1, order_notes: 1, delivery_zone_id: 1,
    save_address: 1, pickup_at: 1, payment_method: 2, payment_plan: 2,
  };

  /*
   * Collection rewrites what this step is even asking. There is no address to
   * check, and there is an appointment that has to be kept -- so the required
   * fields follow the chosen zone rather than being fixed.
   */
  const isPickup = Boolean(selectedZone?.is_pickup);

  // A slot is only a slot once both halves are answered.
  const pickupAt = pickupDate && pickupTime ? `${pickupDate}T${pickupTime}` : '';

  const REQUIRED_ON_DELIVERY = {
    customer_name: 'Please tell us who is receiving the goat.',
    customer_phone: 'We need a phone number for the delivery.',
    customer_email: 'We need an email to send the order confirmation to.',
    address_line: 'Please give us a street address.',
    city: 'Please give us a city or district.',
    area: 'Please tell us the area or thana.',
    postal_code: 'Please give us a postal code.',
  };

  // The same checks the server will run, asked early enough to be useful.
  const problemsOn = (which) => {
    const found = {};

    if (which === 1) {
      // The address questions are not asked when the buyer is collecting, so
      // they cannot be answered, so they must not be demanded.
      const ADDRESS_FIELDS = ['address_line', 'city', 'area', 'postal_code'];

      Object.entries(REQUIRED_ON_DELIVERY).forEach(([key, message]) => {
        if (isPickup && ADDRESS_FIELDS.includes(key)) return;
        if (!String(form[key] || '').trim()) found[key] = [message];
      });

      if (!form.delivery_zone_id) {
        found.delivery_zone_id = ['Choose where the goat is going.'];
      }

      /*
       * The same window the server enforces, checked here so a buyer learns
       * about it while looking at the field rather than after a rejected
       * order. A date input lets you type straight past its own min and max,
       * which is how a December date reached the server at all -- and the
       * refusal that came back talked about times, not dates.
       *
       * Read from the API rather than restated, so the two cannot drift.
       */
      if (isPickup) {
        const bookable = options?.pickup;

        if (!pickupDate || !pickupTime) {
          found.pickup_at = ['Pick the day and time you will come for the goat.'];
        } else if (bookable && pickupDate < bookable.earliest_date) {
          found.pickup_at = [
            `We need time to have the goat ready, so the earliest is ${formatDate(bookable.earliest_date)}.`,
          ];
        } else if (bookable && pickupDate > bookable.latest_date) {
          found.pickup_at = [
            `We are only taking collections up to ${formatDate(bookable.latest_date)} for now. `
            + 'Call us to arrange a later date.',
          ];
        } else if (bookable && !bookable.slots?.includes(pickupTime)) {
          found.pickup_at = ['Please choose one of the times on the list.'];
        }
      }
    }

    if (which === 2 && !selectedMethod) {
      found.payment_method = ['Choose how you would like to pay.'];
    }

    return found;
  };

  const goTo = (target) => {
    // Which way the panes travel. Going back has to look like going back, or
    // correcting an address from the review reads as another step forward.
    setForward(target >= step);
    setStep(target);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const advance = () => {
    const found = problemsOn(step);

    if (Object.keys(found).length) {
      setErrors(found);
      toast.error('Please finish this step first.');
      return;
    }

    setErrors({});
    goTo(Math.min(step + 1, 3));
  };

  const submit = async (event) => {
    event.preventDefault();

    // Enter inside a field submits the form, so the last step is the only one
    // that may place an order -- everywhere else this just moves forward.
    if (step < 3) {
      advance();
      return;
    }

    setPlacing(true);
    setErrors({});

    try {
      const response = await apiFetch('/checkout', {
        method: 'POST',
        token,
        body: {
          ...form,
          payment_method: selectedMethod?.code || form.payment_method,
          payment_plan: paymentPlan,
          // Sent only for collection. On a delivery this stays null, which is
          // what tells every screen afterwards that a van is involved.
          pickup_at: isPickup ? pickupAt : null,
          // What the summary showed is exactly what gets ordered. Sent as cart
          // lines, not goats, so one weight of a listing can be bought without
          // the buyer's other weight of it coming too.
          cart_item_ids: buyOnly ? lineItems.map((item) => item.id) : undefined,
        },
      });
      await refresh();

      const number = response.data.order_number;
      const method = selectedMethod?.code;

      /*
       * A gateway order is not finished when the order is created -- that is
       * the point at which the buyer still has to pay. Send them straight on
       * rather than to an order page that would only ask them to come back.
       */
      if (isGatewayMethod(method)) {
        toast.success('Order placed. Taking you to ' + selectedMethod.name + '…');

        try {
          const result = await payThroughGateway(number, method, token);

          // Only reached if there was nothing to pay; otherwise the browser
          // has already left for the provider.
          if (result.settled) {
            router.push(`/account/orders/${number}?placed=1`);
          }

          return;
        } catch (error) {
          // The order exists and is unpaid, which the order page can explain
          // and offer to retry. Losing them here would be worse.
          toast.error(error.message || 'Could not open the payment page.');
          router.push(`/account/orders/${number}?placed=1&payment=failed`);

          return;
        }
      }

      toast.success(response.message);
      router.push(`/account/orders/${number}?placed=1`);
    } catch (error) {
      const failed = error.errors || {};
      setErrors(failed);

      const owning = Object.keys(failed).map((key) => STEP_OF_FIELD[key]).filter(Boolean);
      if (owning.length) goTo(Math.min(...owning));

      toast.error(error.errors?.cart?.[0] || error.message || 'Could not place your order.');
    } finally {
      setPlacing(false);
    }
  };

  if (authLoading) {
    return <div className="section"><div className="container text-center"><span className="spinner-border text-brand" /></div></div>;
  }

  if (!isAuthenticated) {
    return (
      <div className="section">
        <div className="container">
          <div className="empty">
            <i className="bi bi-person-lock" />
            <h1 className="h4">Sign in to check out</h1>
            <p>You need an account so we can send you order updates.</p>
            <Link href="/login" className="btn btn-brand px-4">Sign in</Link>
          </div>
        </div>
      </div>
    );
  }

  // A stale ?buy= — the goat was removed, or already bought in another tab.
  // Better to say so than to show an empty summary with a live Place order.
  if (buyOnly && !lineItems.length) {
    return (
      <div className="section">
        <div className="container">
          <div className="empty">
            <i className="bi bi-bag-x" />
            <h1 className="h4">That goat is no longer in your cart</h1>
            <p className="text-soft">It may have been removed, or already ordered.</p>
            <div className="d-flex gap-2 justify-content-center">
              {cart?.items?.length > 0 && (
                <Link href="/checkout" className="btn btn-brand px-4">Check out my cart</Link>
              )}
              <Link href="/shop" className="btn btn-quiet px-4">Browse goats</Link>
            </div>
          </div>
        </div>
      </div>
    );
  }

  if (!cart?.items?.length) {
    return (
      <div className="section">
        <div className="container">
          <div className="empty">
            <i className="bi bi-bag" />
            <h1 className="h4">Nothing to check out</h1>
            <Link href="/shop" className="btn btn-brand px-4">Browse goats</Link>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="section">
      <div className="container">
        <div className="checkout__head">
          <h1 className="section-title mb-0">Checkout</h1>
          <Link href="/cart" className="btn btn-quiet btn-sm">
            <i className="bi bi-chevron-left me-1" aria-hidden="true" />
            Back to cart
          </Link>
        </div>

        <CheckoutSteps current={step} onJump={goTo} />

        {/* Our own checks run per step, so the browser's all-at-once pass would
            only fire on fields that are no longer mounted. */}
        <form onSubmit={submit} noValidate>
          <div className="row g-4">
            <div className="col-lg-8">
              {/*
                * mode="wait" so the outgoing step is gone before the next one
                * arrives -- two half-faded forms overlapping is worse than a
                * beat of nothing, and the beat is covered by the scroll to the
                * top that goTo() already performs.
                *
                * Keyed on the step, so 1 -> 2 animates as well as 2 -> 3. That
                * remounts CheckoutFields between the delivery and payment
                * steps, which is safe: it holds no state of its own, every
                * value it shows is a prop from the form state up here.
                */}
              <AnimatePresence mode="wait" custom={forward} initial={false}>
                <m.div
                  key={step}
                  custom={forward}
                  variants={stepPane}
                  initial="enter"
                  animate="centre"
                  exit="exit"
                  transition={TRANSITION.fast}
                >
                  {step === 3 ? (
                    <CheckoutReview
                      form={form}
                      options={options}
                      settings={settings}
                      selectedZone={selectedZone}
                      selectedMethod={selectedMethod}
                      deliveryCharge={deliveryCharge}
                      orderTotal={orderTotal}
                      paymentPlan={paymentPlan}
                      onEdit={goTo}
                    />
                  ) : (
                    <CheckoutFields
                      form={form}
                      errors={errors}
                      update={update}
                      prefilled={prefilled}
                      options={options}
                      settings={settings}
                      selectedZone={selectedZone}
                      selectedMethod={selectedMethod}
                      subtotalAfterDiscount={subtotalAfterDiscount}
                      orderTotal={orderTotal}
                      paymentPlan={paymentPlan}
                      step={step}
                      pickupDate={pickupDate}
                      pickupTime={pickupTime}
                      onPickupDate={(event) => {
                        setPickupDate(event.target.value);
                        setErrors((current) => ({ ...current, pickup_at: undefined }));
                      }}
                      onPickupTime={(event) => {
                        setPickupTime(event.target.value);
                        setErrors((current) => ({ ...current, pickup_at: undefined }));
                      }}
                    />
                  )}
                </m.div>
              </AnimatePresence>
            </div>

            <div className="col-lg-4">
              <div className="checkout__aside">
                <CartSummary
                  deliveryCharge={deliveryCharge}
                  showCoupon={!buyOnly}
                  totals={buyOnly ? { subtotal: subtotalAfterDiscount, discount: 0, total: subtotalAfterDiscount } : null}
                  items={lineItems}
                />

                <div className="checkout__nav">
                  {step < 3 ? (
                    <button className="btn btn-brand btn-lg w-100" type="submit">
                      {step === 1 ? 'Continue to payment' : 'Review order'}
                    </button>
                  ) : (
                    <button className="btn btn-brand btn-lg w-100" type="submit" disabled={placing}>
                      {placing ? 'Placing order…' : 'Place order'}
                    </button>
                  )}

                  {step > 1 && (
                    <button type="button" className="btn btn-quiet w-100" onClick={() => goTo(step - 1)}>
                      <i className="bi bi-chevron-left me-1" aria-hidden="true" />
                      Back
                    </button>
                  )}
                </div>

                <p className="small text-soft text-center mt-3 mb-0">
                  {paymentPlan === 'on_delivery' || !paymentPlan
                    ? 'Nothing is charged online. Payment is collected on delivery.'
                    : 'We will show you where to send the money as soon as the order is placed.'}
                </p>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
}

/**
 * useSearchParams() bails out of static rendering, so the page that reads
 * ?buy= sits behind a boundary — same shape as the seller listings page.
 */
export default function CheckoutPage() {
  return (
    <Suspense fallback={(
      <div className="section">
        <div className="container text-center py-5">
          <span className="spinner-border text-brand" role="status" />
        </div>
      </div>
    )}>
      <CheckoutInner />
    </Suspense>
  );
}
