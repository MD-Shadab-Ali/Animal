'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useCart } from '@/context/CartContext';
import { useSettings } from '@/context/SiteContext';
import { formatMoney } from '@/lib/format';

export default function BuyBox({ goat }) {
  const router = useRouter();
  const settings = useSettings();
  const { addItem, toggleWishlist, isInWishlist, cartItemFor, loading } = useCart();
  const [quantity, setQuantity] = useState(1);

  const max = goat.track_stock ? Math.max(1, goat.stock) : 99;
  const saved = isInWishlist(goat.id);

  const inCart = cartItemFor(goat.id);
  // Unique animals are usually stock 1, so once it is in the cart there is
  // normally nothing left to add.
  const canAddMore = !goat.track_stock || (inCart?.quantity ?? 0) < goat.stock;
  const lowStock = goat.track_stock && goat.stock > 0 && goat.stock <= 2;

  const buyNow = async () => {
    const result = await addItem(goat.id, quantity);
    if (result) router.push('/checkout');
  };

  return (
    <div className="buybox buybox--sticky">
      <div className="d-flex align-items-baseline flex-wrap gap-2 mb-2">
        <span className="price-now">{formatMoney(goat.effective_price, settings)}</span>
        {goat.is_on_sale && (
          <>
            <span className="price-was">{formatMoney(goat.price, settings)}</span>
            <span className="badge-sale">Save {goat.discount_percent}%</span>
          </>
        )}
      </div>

      <div className="mb-3 small">
        {goat.is_available ? (
          <span className={lowStock ? 'text-warning fw-semibold' : 'text-brand fw-semibold'}>
            <i className={`bi ${lowStock ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'} me-1`} aria-hidden="true" />
            {goat.track_stock
              ? (lowStock ? `Only ${goat.stock} left` : `${goat.stock} available`)
              : 'Available now'}
          </span>
        ) : (
          <span className="text-danger fw-semibold">
            <i className="bi bi-x-circle-fill me-1" aria-hidden="true" /> Sold
          </span>
        )}
      </div>

      {goat.is_available && (
        <>
          {max > 1 && !inCart && (
            <div className="mb-3">
              <span className="form-label d-block">Quantity</span>
              <div className="qty">
                <button
                  type="button"
                  onClick={() => setQuantity((value) => Math.max(1, value - 1))}
                  disabled={quantity <= 1}
                  aria-label="Decrease quantity"
                >
                  <i className="bi bi-dash" aria-hidden="true" />
                </button>
                <span aria-live="polite">{quantity}</span>
                <button
                  type="button"
                  onClick={() => setQuantity((value) => Math.min(max, value + 1))}
                  disabled={quantity >= max}
                  aria-label="Increase quantity"
                >
                  <i className="bi bi-plus" aria-hidden="true" />
                </button>
              </div>
            </div>
          )}

          {inCart ? (
            <>
              <p className="d-flex align-items-center gap-2 text-brand fw-semibold mb-3" aria-live="polite">
                <i className="bi bi-check2-circle" aria-hidden="true" />
                In your cart{inCart.quantity > 1 ? ` — ${inCart.quantity} of them` : ''}
              </p>

              <div className="d-grid gap-2 mb-2">
                <Link href="/checkout" className="btn btn-cta btn-lg">
                  Go to checkout <i className="bi bi-arrow-right" aria-hidden="true" />
                </Link>

                <Link href="/cart" className="btn btn-outline-brand">
                  <i className="bi bi-bag" aria-hidden="true" /> View cart
                </Link>

                {canAddMore && (
                  <button
                    className="btn btn-link text-decoration-none"
                    onClick={() => addItem(goat.id, 1)}
                    disabled={loading}
                  >
                    Add another
                  </button>
                )}
              </div>
            </>
          ) : (
            <div className="d-grid gap-2 mb-2">
              <button className="btn btn-cta btn-lg" onClick={buyNow} disabled={loading}>
                {loading
                  ? <span className="spinner-border spinner-border-sm" aria-hidden="true" />
                  : <>Buy now <i className="bi bi-arrow-right" aria-hidden="true" /></>}
              </button>

              <button
                className="btn btn-outline-brand"
                onClick={() => addItem(goat.id, quantity)}
                disabled={loading}
              >
                <i className="bi bi-bag-plus" aria-hidden="true" /> Add to cart
              </button>
            </div>
          )}
        </>
      )}

      {settings.enable_wishlist !== false && (
        <button
          className={`btn btn-link w-100 text-decoration-none ${saved ? 'text-danger' : 'text-soft'}`}
          onClick={() => toggleWishlist(goat.id)}
          aria-pressed={saved}
        >
          <i className={`bi ${saved ? 'bi-heart-fill' : 'bi-heart'} me-1`} aria-hidden="true" />
          {saved ? 'Saved to wishlist' : 'Save for later'}
        </button>
      )}

      <hr />

      <ul className="list-unstyled small text-soft mb-0 d-grid gap-2">
        <li><i className="bi bi-cash-coin text-brand me-2" aria-hidden="true" />Cash on delivery — inspect before you pay</li>
        <li><i className="bi bi-patch-check text-brand me-2" aria-hidden="true" />Veterinary certificate included</li>
        <li><i className="bi bi-truck text-brand me-2" aria-hidden="true" />Delivery charge shown at checkout</li>
        <li><i className="bi bi-arrow-counterclockwise text-brand me-2" aria-hidden="true" />Refuse at the door if it does not match</li>
      </ul>
    </div>
  );
}
