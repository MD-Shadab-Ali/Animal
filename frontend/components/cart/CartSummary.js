'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useCart } from '@/context/CartContext';
import { useSettings } from '@/context/SiteContext';
import { formatMoney } from '@/lib/format';

/**
 * Shared between the cart and checkout pages. `deliveryCharge` is passed in
 * on checkout once the customer picks a zone; on the cart page it is unknown.
 */
export default function CartSummary({
  showCheckoutButton = false,
  deliveryCharge = null,
  // Buying a single goat rather than the cart: the figures come from that one
  // item, and a whole-basket coupon has nothing to attach itself to.
  totals: totalsOverride = null,
  showCoupon = true,
  // What is being bought. Rendered above the figures, because a summary reads
  // "here is what you are getting, here is what it costs" — a line item
  // stranded under the total looks like an afterthought, or worse, an extra.
  items = null,
  children,
}) {
  const { cart, loading, applyCoupon, removeCoupon } = useCart();
  const settings = useSettings();
  const [code, setCode] = useState('');

  const totals = totalsOverride || cart?.totals || {};
  const grandTotal = deliveryCharge === null
    ? totals.total
    : Number(totals.total || 0) + Number(deliveryCharge || 0);

  const submitCoupon = async (event) => {
    event.preventDefault();
    if (!code.trim()) return;

    try {
      await applyCoupon(code.trim());
      setCode('');
    } catch {
      // CartContext already surfaced the error as a toast.
    }
  };

  return (
    <div className="panel">
      <h2 className="h6 mb-3">Order summary</h2>

      {items?.length > 0 && (
        <>
          <ul className="list-unstyled small mb-0">
            {items.map((item) => (
              <li className="d-flex justify-content-between gap-3 py-1" key={item.id}>
                <span className="text-ink">
                  {item.goat?.name || item.name}
                  {item.quantity > 1 && (
                    <span className="text-soft"> &times; {item.quantity}</span>
                  )}
                </span>
                <span className="text-nowrap flex-shrink-0">
                  {formatMoney(item.line_total, settings)}
                </span>
              </li>
            ))}
          </ul>

          <hr className="my-3" />
        </>
      )}

      {showCoupon && settings.enable_coupons !== false && (
        <div className="mb-3">
          {cart?.coupon ? (
            <div className="d-flex justify-content-between align-items-center bg-surface rounded p-2 small">
              <span><i className="bi bi-tag me-1 text-brand" />{cart.coupon.code}</span>
              <button className="btn btn-link btn-sm text-danger p-0 text-decoration-none" onClick={removeCoupon}>
                Remove
              </button>
            </div>
          ) : (
            <form onSubmit={submitCoupon} className="input-group input-group-sm">
              <input
                className="form-control"
                placeholder="Coupon code"
                value={code}
                onChange={(event) => setCode(event.target.value)}
              />
              <button className="btn btn-outline-brand" type="submit" disabled={loading}>Apply</button>
            </form>
          )}
        </div>
      )}

      <dl className="row small mb-3">
        <dt className="col-7 fw-normal text-soft">Subtotal</dt>
        <dd className="col-5 text-end mb-1">{formatMoney(totals.subtotal, settings)}</dd>

        {totals.discount > 0 && (
          <>
            <dt className="col-7 fw-normal text-soft">Discount</dt>
            <dd className="col-5 text-end mb-1 text-success">−{formatMoney(totals.discount, settings)}</dd>
          </>
        )}

        <dt className="col-7 fw-normal text-soft">Delivery</dt>
        <dd className="col-5 text-end mb-0">
          {deliveryCharge === null
            ? <span className="text-soft">At checkout</span>
            : (Number(deliveryCharge) === 0 ? <span className="text-success">Free</span> : formatMoney(deliveryCharge, settings))}
        </dd>
      </dl>

      <hr />

      <div className="d-flex justify-content-between align-items-baseline mb-3">
        <span className="fw-semibold">Total</span>
        <span className="h5 mb-0 text-brand fw-bold">{formatMoney(grandTotal, settings)}</span>
      </div>

      {children}

      {showCheckoutButton && (
        <Link href="/checkout" className="btn btn-brand w-100">
          Checkout <i className="bi bi-arrow-right ms-1" />
        </Link>
      )}
    </div>
  );
}
