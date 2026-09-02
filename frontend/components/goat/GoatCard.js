'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useCart } from '@/context/CartContext';
import { useSettings } from '@/context/SiteContext';

/**
 * A listing, as a full-bleed tile.
 *
 * The photograph fills the card, a themed wash rises from the bottom, and the
 * whole surface answers the pointer together -- the picture pushing in slightly
 * while the tile lifts. Styles live in globals.scss under .goat-tile.
 *
 * Everything the card could do before, it still does: the wishlist heart, the
 * sale and vet badges, the sold-out and already-in-cart states, and an action
 * that reflects whether the listing is sold by the kilo.
 */
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
    <article className="goat-tile rise" style={{ animationDelay: `${Math.min(index, 8) * 45}ms` }}>
      <div className="goat-tile__media">
        {goat.thumbnail
          ? <img src={goat.thumbnail} alt={goat.name} loading="lazy" />
          : <div className="goat-tile__empty"><i className="bi bi-image" aria-hidden="true" /></div>}
      </div>

      <div className="goat-tile__wash" />

      <div className="goat-tile__tags">
        {goat.is_on_sale && <span className="badge-sale">−{goat.discount_percent}%</span>}
        {!goat.is_available && <span className="badge-sold">Sold</span>}
        {/* The label is its own element so a card too narrow to seat it beside
            the discount can drop the wording and keep the tick, rather than
            pushing the whole badge onto a second row. It stays in the
            accessibility tree either way. */}
        {goat.is_vaccinated && goat.is_available && (
          <span className="badge-verified" title="Vet checked">
            <i className="bi bi-patch-check-fill" aria-hidden="true" />
            <span className="badge-verified__label">Vet checked</span>
          </span>
        )}
      </div>

      {wishlistOn && (
        <button
          type="button"
          className={`goat-tile__wish ${saved ? 'is-on' : ''}`}
          onClick={() => toggleWishlist(goat.id)}
          aria-label={saved ? `Remove ${goat.name} from wishlist` : `Save ${goat.name} to wishlist`}
          aria-pressed={saved}
        >
          <i className={`bi ${saved ? 'bi-heart-fill' : 'bi-heart'}`} aria-hidden="true" />
        </button>
      )}

      <div className="goat-tile__body">
        {goat.category?.name && <span className="goat-tile__cat">{goat.category.name}</span>}

        <Link href={`/goats/${goat.slug}`} className="goat-tile__name">{goat.name}</Link>

        {goat.sold_by && (
          <span className="goat-tile__seller">
            <i className={`bi ${goat.sold_by.type === 'house' ? 'bi-house-heart' : 'bi-shop'}`} aria-hidden="true" />
            {goat.sold_by.name}
            {goat.sold_by.is_verified && (
              <i className="bi bi-patch-check-fill" title="Verified seller" aria-label="Verified seller" />
            )}
          </span>
        )}

        {!goat.is_available && (
          <button type="button" className="goat-tile__action" disabled>
            <span>Sold out</span>
          </button>
        )}

        {goat.is_available && inCart && (
          <Link href="/cart" className="goat-tile__action">
            <span>In cart{inCart.quantity > 1 ? ` (${inCart.quantity})` : ''} — view</span>
            <i className="bi bi-check2-circle" aria-hidden="true" />
          </Link>
        )}

        {/*
          A listing sold by the kilo cannot be added from here.

          Adding it would mean choosing a weight on the buyer's behalf, and the
          weight is the price: some listings run from one figure to more than
          double it. Picking the listed weight for them is not a shortcut, it is
          a decision they did not make -- and since the card carries no price,
          one they cannot even see. A listing with a single fixed price has
          nothing to choose, and keeps its one-click buy.
        */}
        {goat.is_available && !inCart && goat.pricing?.is_per_kg && (
          <Link href={`/goats/${goat.slug}`} className="goat-tile__action">
            <span>Choose weight</span>
            <i className="bi bi-arrow-right" aria-hidden="true" />
          </Link>
        )}

        {goat.is_available && !inCart && !goat.pricing?.is_per_kg && (
          <button
            type="button"
            className="goat-tile__action"
            onClick={add}
            disabled={adding || loading}
          >
            <span>{adding ? 'Adding…' : 'Add to cart'}</span>
            {adding
              ? <span className="spinner-border spinner-border-sm" aria-hidden="true" />
              : <i className="bi bi-bag-plus" aria-hidden="true" />}
          </button>
        )}
      </div>
    </article>
  );
}
