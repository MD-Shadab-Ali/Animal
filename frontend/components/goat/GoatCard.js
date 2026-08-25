'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useCart } from '@/context/CartContext';
import { useSettings } from '@/context/SiteContext';
import { formatMoney, formatAge } from '@/lib/format';

export default function GoatCard({ goat, index = 0 }) {
  const settings = useSettings();
  const { addItem, toggleWishlist, isInWishlist, cartItemFor, loading } = useCart();

  const saved = isInWishlist(goat.id);
  const wishlistOn = settings.enable_wishlist !== false;

  const inCart = cartItemFor(goat.id);
  const [adding, setAdding] = useState(false);

  const add = async () => {
    setAdding(true);
    try {
      await addItem(goat.id);
    } catch {
      // CartContext already showed the reason.
    } finally {
      setAdding(false);
    }
  };

  return (
    <article className="card-goat rise" style={{ animationDelay: `${Math.min(index, 8) * 45}ms` }}>
      <div className="card-goat__media">
        <Link href={`/goats/${goat.slug}`} aria-label={goat.name}>
          {goat.thumbnail
            ? <img src={goat.thumbnail} alt={goat.name} loading="lazy" />
            : <div className="card-goat__empty"><i className="bi bi-image" aria-hidden="true" /></div>}
        </Link>

        <div className="card-goat__tags">
          {goat.is_on_sale && <span className="badge-sale">−{goat.discount_percent}%</span>}
          {!goat.is_available && <span className="badge-sold">Sold</span>}
          {goat.is_vaccinated && goat.is_available && (
            <span className="badge-verified">
              <i className="bi bi-patch-check-fill" aria-hidden="true" /> Vet checked
            </span>
          )}
        </div>

        {wishlistOn && (
          <button
            type="button"
            className={`card-goat__wish ${saved ? 'is-on' : ''}`}
            onClick={() => toggleWishlist(goat.id)}
            aria-label={saved ? `Remove ${goat.name} from wishlist` : `Save ${goat.name} to wishlist`}
            aria-pressed={saved}
          >
            <i className={`bi ${saved ? 'bi-heart-fill' : 'bi-heart'}`} aria-hidden="true" />
          </button>
        )}
      </div>

      <div className="card-goat__body">
        {goat.category?.name && <span className="card-goat__cat">{goat.category.name}</span>}

        {goat.sold_by && (
          <span className="small text-soft d-flex align-items-center gap-1">
            <i className={`bi ${goat.sold_by.type === 'house' ? 'bi-house-heart' : 'bi-shop'}`} aria-hidden="true" />
            {goat.sold_by.name}
            {goat.sold_by.is_verified && (
              <i className="bi bi-patch-check-fill text-brand" title="Verified seller" aria-label="Verified seller" />
            )}
          </span>
        )}

        <Link href={`/goats/${goat.slug}`} className="card-goat__name">{goat.name}</Link>

        <div className="card-goat__specs">
          {goat.breed && <span>{goat.breed}</span>}
          {goat.weight_kg && <span>{goat.weight_kg} kg</span>}
          {goat.age_months != null && <span>{formatAge(goat.age_months)}</span>}
        </div>

        <div className="card-goat__foot">
          <div className="card-goat__price mb-2">
            {formatMoney(goat.effective_price, settings)}
            {goat.is_on_sale && <del>{formatMoney(goat.price, settings)}</del>}
          </div>

          {!goat.is_available && (
            <button type="button" className="btn btn-quiet btn-sm w-100" disabled>
              Sold out
            </button>
          )}

          {goat.is_available && inCart && (
            <Link href="/cart" className="btn btn-outline-brand btn-sm w-100">
              <i className="bi bi-check2-circle" aria-hidden="true" />
              In cart{inCart.quantity > 1 ? ` (${inCart.quantity})` : ''} — view
            </Link>
          )}

          {goat.is_available && !inCart && (
            <button
              type="button"
              className="btn btn-cta btn-sm w-100"
              onClick={add}
              disabled={adding || loading}
            >
              {adding ? (
                <><span className="spinner-border spinner-border-sm" aria-hidden="true" /> Adding…</>
              ) : (
                <><i className="bi bi-bag-plus" aria-hidden="true" /> Add to cart</>
              )}
            </button>
          )}
        </div>
      </div>
    </article>
  );
}
