'use client';

import { formatDate, formatMoney } from '@/lib/format';

export const PLAN_LABELS = {
  full: {
    title: 'Pay in full now',
    hint: 'The whole amount up front. Nothing to hand over at the door.',
  },
  advance: {
    title: 'Pay an advance now',
    hint: 'Reserve the goat today and settle the rest when it arrives.',
  },
  on_delivery: {
    title: 'Pay on delivery',
    hint: 'Nothing now — pay when the goat reaches you.',
  },
};

/** What the buyer hands over today under each plan. */
export function planAmount(plan, total, method, options) {
  if (plan === 'full') return total;
  if (plan === 'on_delivery') return 0;

  // The method may set its own advance, as rupees or as a share of the order;
  // with nothing set it falls back to the site-wide percentage.
  const advance = method?.advance_amount == null
    ? total * ((options?.advance_percent ?? 30) / 100)
    : (method.advance_type === 'fixed'
      ? method.advance_amount
      : total * (method.advance_amount / 100));

  return Math.min(Math.round(advance * 100) / 100, total);
}

export default function CheckoutFields({
  form, errors, update, prefilled,
  options, settings, selectedZone, selectedMethod, subtotalAfterDiscount, orderTotal,
  paymentPlan, step = 1,
  pickupDate, pickupTime, onPickupDate, onPickupTime,
}) {
  // Collection is a property of the zone the buyer chose, so everything below
  // follows from that one answer rather than from a second question.
  const isPickup = Boolean(selectedZone?.is_pickup);
  const pickup = options?.pickup;
  const field = (key, label, { type = 'text', required = false, colClass = 'col-md-6', as = 'input', rows } = {}) => (
    <div className={colClass} key={key}>
      <label className="form-label" htmlFor={key}>
        {label} {required && <span className="text-danger">*</span>}
      </label>
      {as === 'textarea' ? (
        <textarea
          id={key}
          rows={rows || 3}
          className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
          value={form[key]}
          onChange={update(key)}
        />
      ) : (
        <input
          id={key}
          type={type}
          className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
          value={form[key]}
          onChange={update(key)}
          required={required}
        />
      )}
      {errors[key] && <div className="invalid-feedback">{errors[key][0]}</div>}
    </div>
  );

  const onDelivery = step === 1;
  const onPayment = step === 2;

  return (
    <div className="d-grid gap-4">
      {onDelivery && (
      <section className="panel">
        <h2 className="h6 mb-1">{isPickup ? 'Your details' : 'Delivery details'}</h2>
        <p className="small text-soft mb-3">
          {isPickup
            ? 'Who is coming for the goat, so we know who to hand it to and how to reach you if anything changes.'
            : (prefilled
              ? 'Filled in from your account. Change anything here for this order only, or update it in your addresses.'
              : 'Where the goat is going, and who to call on arrival. We will keep this on your account so the next order fills itself in.')}
        </p>
        <div className="row g-3">
          {field('customer_name', 'Full name', { required: true })}
          {field('customer_phone', 'Phone number', { type: 'tel', required: true })}
          {field('customer_email', 'Email', { type: 'email', colClass: 'col-12', required: true })}

          {/* Nothing is being delivered, so there is nowhere to deliver it to.
              Asking for a postal code we will never read only makes the buyer
              wonder which address we intend to use. */}
          {!isPickup && field('address_line', 'Street address', { required: true })}
          {!isPickup && field('city', 'City / district', { required: true })}
          {!isPickup && field('area', 'Area / thana', { required: true })}
          {!isPickup && field('postal_code', 'Postal code', { required: true })}
        </div>
      </section>

      )}

      {onDelivery && (
      <section className="panel">
        <h2 className="h6 mb-3" id="delivery-zone-heading">Delivery zone</h2>

        {/*
         * One choice out of three does not need three cards. The price rides
         * along in each option, and whatever the chosen zone had to say for
         * itself -- where it covers, how long it takes -- moves underneath,
         * where it is read once instead of three times.
         */}
        <select
          id="delivery_zone_id"
          aria-labelledby="delivery-zone-heading"
          className={`form-select ${errors.delivery_zone_id ? 'is-invalid' : ''}`}
          value={form.delivery_zone_id}
          onChange={update('delivery_zone_id')}
        >
          {!form.delivery_zone_id && <option value="">Choose a delivery zone…</option>}

          {(options?.delivery_zones || []).map((zone) => {
            const isFree = zone.free_above !== null && subtotalAfterDiscount >= zone.free_above;

            return (
              <option key={zone.id} value={zone.id}>
                {zone.name} — {isFree ? 'Free' : formatMoney(zone.charge, settings)}
              </option>
            );
          })}
        </select>

        {errors.delivery_zone_id && (
          <div className="invalid-feedback d-block">{errors.delivery_zone_id[0]}</div>
        )}

        {selectedZone && (selectedZone.description || selectedZone.estimated_time) && (
          <p className="small text-soft mt-2 mb-0">
            {selectedZone.description}
            {selectedZone.description && selectedZone.estimated_time ? ' · ' : ''}
            {selectedZone.estimated_time && `Arrives in ${selectedZone.estimated_time}`}
          </p>
        )}

        {!isPickup && selectedZone?.free_above && subtotalAfterDiscount < selectedZone.free_above && (
          <p className="small text-soft mt-2 mb-0">
            Spend {formatMoney(selectedZone.free_above - subtotalAfterDiscount, settings)} more for free delivery in this zone.
          </p>
        )}

        {/*
         * The whole point of offering collection at all. An open invitation to
         * come by sometime is how somebody ends up at a farm gate at dusk with
         * an animal and no way home; naming an hour in advance means the goat
         * is ready and somebody is expecting them.
         */}
        {isPickup && pickup && (
          <div className="mt-3 pt-3 border-top">
            <div className="row g-3">
              <div className="col-md-6">
                <label className="form-label" htmlFor="pickup_date">
                  Day you will come <span className="text-danger">*</span>
                </label>
                <input
                  id="pickup_date"
                  type="date"
                  className={`form-control ${errors.pickup_at ? 'is-invalid' : ''}`}
                  min={pickup.earliest_date}
                  max={pickup.latest_date}
                  value={pickupDate || ''}
                  onChange={onPickupDate}
                />
              </div>

              <div className="col-md-6">
                <label className="form-label" htmlFor="pickup_time">
                  Time <span className="text-danger">*</span>
                </label>
                <select
                  id="pickup_time"
                  className={`form-select ${errors.pickup_at ? 'is-invalid' : ''}`}
                  value={pickupTime || ''}
                  onChange={onPickupTime}
                >
                  <option value="">Choose a time…</option>
                  {(pickup.slots || []).map((slot) => (
                    <option key={slot} value={slot}>{slot}</option>
                  ))}
                </select>
              </div>
            </div>

            {errors.pickup_at && (
              <div className="invalid-feedback d-block">{errors.pickup_at[0]}</div>
            )}

            {/* The window, said before anybody picks. A buyer who chooses a
                date months out and is only told afterwards has been let walk
                into it -- and the raw 2026-09-03 this used to print was not a
                date anyone reads aloud. */}
            <p className="small text-soft mt-2 mb-0">
              {pickup.address && <>Come to <strong className="text-ink">{pickup.address}</strong>. </>}
              Any day from {formatDate(pickup.earliest_date)} to {formatDate(pickup.latest_date)},
              on the hour between {pickup.slots?.[0]} and {pickup.slots?.[pickup.slots.length - 1]}.
            </p>

            {pickup.instructions && (
              <p className="small text-soft mt-2 mb-0">{pickup.instructions}</p>
            )}
          </div>
        )}
      </section>

      )}

      {onPayment && (
      <section className="panel">
        <h2 className="h6 mb-3" id="payment-method-heading">Payment</h2>

        {/*
         * Bound to the derived method, not to the raw field: an admin can make
         * a method delivery-only at any time, and the fallback has to be what
         * shows as chosen.
         */}
        <select
          id="payment_method"
          aria-labelledby="payment-method-heading"
          className={`form-select ${errors.payment_method ? 'is-invalid' : ''}`}
          value={selectedMethod?.code || ''}
          onChange={update('payment_method')}
        >
          {(options?.payment_methods || []).map((method) => (
            <option
              key={method.code}
              value={method.code}
              // Still listed, so the buyer can see it exists and how it works,
              // but it cannot start an order.
              disabled={method.selectable === false}
            >
              {method.name}{method.selectable === false ? ' — on delivery only' : ''}
            </option>
          ))}
        </select>

        {errors.payment_method && (
          <div className="invalid-feedback d-block">{errors.payment_method[0]}</div>
        )}

        {(selectedMethod?.instructions || selectedMethod?.logo) && (
          <div className="d-flex align-items-center gap-2 mt-2">
            {selectedMethod.logo && (
              <img src={selectedMethod.logo} alt="" style={{ height: 20 }} />
            )}
            {selectedMethod.instructions && (
              <span className="small text-soft">{selectedMethod.instructions}</span>
            )}
          </div>
        )}

        {/* Why the greyed-out one is greyed out. It answers a question the
            disabled option raises but cannot itself explain. */}
        {(options?.payment_methods || [])
          .filter((method) => method.selectable === false && method.unavailable_reason)
          .map((method) => (
            <p key={method.code} className="small text-soft mt-2 mb-0">
              <strong className="text-ink">{method.name}:</strong> {method.unavailable_reason}
            </p>
          ))}

        {/* Always shown, even when the method allows only one answer: the buyer
            should never reach "Place order" unsure what they owe today. */}
        {(selectedMethod?.plans?.length || 0) > 0 && (
          <div className="mt-4">
            <h3 className="h6 mb-1" id="payment-plan-heading">
              {selectedMethod.plans.length > 1 ? 'When would you like to pay?' : 'When you pay'}
            </h3>
            <p className="small text-soft mb-2">
              {selectedMethod.requires_advance
                ? `${selectedMethod.name} needs money up front — we hold the goat once it is in.`
                : 'We hold the goat for you once the money is in.'}
            </p>

            <select
              id="payment_plan"
              aria-labelledby="payment-plan-heading"
              className={`form-select ${errors.payment_plan ? 'is-invalid' : ''}`}
              value={paymentPlan}
              onChange={update('payment_plan')}
              disabled={selectedMethod.plans.length === 1}
            >
              {selectedMethod.plans.map((plan) => {
                const amount = planAmount(plan, orderTotal, selectedMethod, options);

                return (
                  <option key={plan} value={plan}>
                    {PLAN_LABELS[plan]?.title}
                    {' — '}
                    {amount > 0 ? formatMoney(amount, settings) : 'Nothing now'}
                  </option>
                );
              })}
            </select>

            {errors.payment_plan && (
              <div className="invalid-feedback d-block">{errors.payment_plan[0]}</div>
            )}

            {PLAN_LABELS[paymentPlan]?.hint && (
              <p className="small text-soft mt-2 mb-0">{PLAN_LABELS[paymentPlan].hint}</p>
            )}
          </div>
        )}

        {selectedMethod?.requires_advance && (selectedMethod.plans || []).indexOf('on_delivery') === -1 && (
          <div className="alert alert-warning small mt-3 mb-0">
            {selectedMethod.name} needs money up front to reserve the goat.
          </div>
        )}
      </section>
      )}
    </div>
  );
}
