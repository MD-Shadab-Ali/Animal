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
  const { cart, addItem, toggleWishlist, isInWishlist, cartItemFor, loading } = useCart();
  const [quantity, setQuantity] = useState(1);

  // A listing advertised at one weight can often be supplied heavier: the buyer
  // picks a weight up to the seller's ceiling and the price scales with it.
  const pricing = goat.pricing || {};
  const perKg = Boolean(pricing.is_per_kg);
  const minKg = Number(pricing.min_weight_kg ?? 0);
  const maxKg = Number(pricing.max_weight_kg ?? 0);
  const stepKg = Number(pricing.step_kg ?? 1);
  // The weight the asking price belongs to. Not the same as the lightest on
  // offer once a seller supplies animals below their advertised weight.
  const anchorKg = Number(pricing.anchor_weight_kg ?? minKg);

  // Opens on the advertised weight so the price on screen is the price the
  // shop and the search results promised.
  const [weight, setWeight] = useState(anchorKg || minKg);

  // Scaled from the advertised price the same way the server scales it. Going
  // through the displayed rate instead would show 29,166.75 against a server
  // charging 29,166.67, because that rate is rounded to two places.
  const unitPrice = perKg && anchorKg > 0
    ? Math.round((goat.effective_price * weight / anchorKg) * 100) / 100
    : goat.effective_price;

  const max = goat.track_stock ? Math.max(1, goat.stock) : 99;
  const saved = isInWishlist(goat.id);

  // Each weight is its own cart line, so "already in your cart" has to be
  // asked about the weight on screen, not about the listing.
  const inCart = cartItemFor(goat.id, perKg ? weight : null);
  // Shown on the cart button so it is obvious the two routes differ.
  const cartCount = cart?.items?.length ?? 0;
  // Unique animals are usually stock 1, so once it is in the cart there is
  // normally nothing left to add.
  const canAddMore = !goat.track_stock || (inCart?.quantity ?? 0) < goat.stock;
  const lowStock = goat.track_stock && goat.stock > 0 && goat.stock <= 2;

  const buyNow = async () => {
    const result = await addItem(goat.id, quantity, perKg ? weight : null);

    // Buy now means *this* goat. Sending them to a bare /checkout would order
    // whatever else is already sitting in the cart along with it.
    if (result) router.push(`/checkout?buy=${goat.id}${perKg ? `&kg=${weight}` : ''}`);
  };

  return (
    <div className="buybox buybox--sticky">
      <div className="d-flex align-items-baseline flex-wrap gap-2 mb-2">
        <span className="price-now">{formatMoney(unitPrice, settings)}</span>
        {perKg ? (
          <span className="text-soft small">
            {formatMoney(pricing.price_per_kg, settings)} / kg
          </span>
        ) : goat.is_on_sale && (
          <>
            <span className="price-was">{formatMoney(goat.price, settings)}</span>
            <span className="badge-sale">Save {goat.discount_percent}%</span>
          </>
        )}
      </div>

      {perKg && (
        <p className="text-soft small mb-2">
          {weight === anchorKg
            ? `As listed at ${anchorKg} kg — choose anything from ${minKg} kg to ${maxKg} kg.`
            : `${weight} kg, priced from ${formatMoney(goat.effective_price, settings)} at ${anchorKg} kg.`}
        </p>
      )}

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
          {perKg && (
            <div className="mb-3">
              <div className="d-flex align-items-center justify-content-between mb-1">
                <span className="form-label mb-0" id="weight-label">Weight</span>
                <span className="fw-semibold" aria-live="polite">{weight} kg</span>
              </div>

              {/* Same stepper the quantity control uses, so the two read as one
                  family rather than as two different ideas of a picker. */}
              <div className="qty mb-2">
                <button
                  type="button"
                  onClick={() => setWeight((value) => Math.max(minKg, Math.round((value - stepKg) * 100) / 100))}
                  disabled={weight <= minKg}
                  aria-label="Less weight"
                >
                  <i className="bi bi-dash" aria-hidden="true" />
                </button>
                <span aria-live="polite">{weight} kg</span>
                <button
                  type="button"
                  onClick={() => setWeight((value) => Math.min(maxKg, Math.round((value + stepKg) * 100) / 100))}
                  disabled={weight >= maxKg}
                  aria-label="More weight"
                >
                  <i className="bi bi-plus" aria-hidden="true" />
                </button>
              </div>

              {/* The slider is for covering a wide range quickly; the stepper
                  above is for landing exactly on a figure. */}
              <input
                type="range"
                className="form-range"
                min={minKg}
                max={maxKg}
                step={stepKg}
                value={weight}
                onChange={(event) => setWeight(Number(event.target.value))}
                aria-labelledby="weight-label"
              />

              <div className="d-flex justify-content-between text-soft small">
                <span>{minKg} kg</span>
                <span>{maxKg} kg</span>
              </div>
            </div>
          )}

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
                {perKg ? `${weight} kg in your cart` : 'In your cart'}
                {inCart.quantity > 1 ? ` — ${inCart.quantity} of them` : ''}
              </p>

              <div className="d-grid gap-2 mb-2">
                {/* Every button in this box is about the goat in this box. A
                    bare /checkout here ordered whatever else was in the cart
                    too — the cart has its own way through, just below. */}
                <Link
                  href={`/checkout?buy=${goat.id}${perKg ? `&kg=${weight}` : ''}`}
                  className="btn btn-cta btn-lg"
                >
                  Check out this goat <i className="bi bi-arrow-right" aria-hidden="true" />
                </Link>

                <Link href="/cart" className="btn btn-outline-brand">
                  <i className="bi bi-bag" aria-hidden="true" />
                  {' '}View cart{cartCount > 1 ? ` (${cartCount})` : ''}
                </Link>

                {canAddMore && (
                  <button
                    className="btn btn-link text-decoration-none"
                    onClick={() => addItem(goat.id, 1, perKg ? weight : null)}
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
                onClick={() => addItem(goat.id, quantity, perKg ? weight : null)}
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
