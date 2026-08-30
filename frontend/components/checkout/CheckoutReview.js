'use client';

import { formatMoney } from '@/lib/format';
import { PLAN_LABELS, planAmount } from './CheckoutFields';

/**
 * The last look before the money moves.
 *
 * Everything here was chosen on an earlier step, so each block links back to
 * the step that owns it rather than repeating the controls: the buyer edits
 * where they entered it, and there is only one field of each kind on the page.
 */
export default function CheckoutReview({
  form, options, settings, selectedZone, selectedMethod,
  deliveryCharge, orderTotal, paymentPlan, onEdit,
}) {
  const dueToday = selectedMethod
    ? planAmount(paymentPlan, orderTotal, selectedMethod, options)
    : 0;

  const block = (title, step, children) => (
    <div className="review__block">
      <div className="review__head">
        <h3 className="h6 mb-0">{title}</h3>
        <button type="button" className="btn btn-link btn-sm p-0 text-brand" onClick={() => onEdit(step)}>
          Edit
        </button>
      </div>
      {children}
    </div>
  );

  return (
    <section className="panel">
      <h2 className="h6 mb-3">Review your order</h2>

      <div className="d-grid gap-3">
        {block('Delivering to', 1, (
          <address className="small text-soft mb-0">
            <strong className="text-ink d-block">{form.customer_name}</strong>
            {form.address_line}
            {form.area ? `, ${form.area}` : ''}
            <br />
            {form.city}
            {form.postal_code ? ` ${form.postal_code}` : ''}
            <br />
            {form.customer_phone}
            {form.customer_email ? ` · ${form.customer_email}` : ''}
          </address>
        ))}

        {selectedZone && block('Delivery', 1, (
          <p className="small text-soft mb-0">
            <strong className="text-ink">{selectedZone.name}</strong>
            {selectedZone.estimated_time ? ` · arrives in ${selectedZone.estimated_time}` : ''}
            <br />
            {Number(deliveryCharge) === 0
              ? <span className="text-success">Free delivery</span>
              : formatMoney(deliveryCharge, settings)}
          </p>
        ))}

        {selectedMethod && block('Payment', 2, (
          <p className="small text-soft mb-0">
            <strong className="text-ink">{selectedMethod.name}</strong>
            <br />
            {PLAN_LABELS[paymentPlan]?.title || 'Pay on delivery'}
            {' · '}
            <span className="fw-semibold text-brand">
              {dueToday > 0 ? `${formatMoney(dueToday, settings)} today` : 'Nothing today'}
            </span>
          </p>
        ))}
      </div>
    </section>
  );
}
