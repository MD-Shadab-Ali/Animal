'use client';

import Link from 'next/link';
import { useCallback, useState } from 'react';
import GoatGrid from '@/components/goat/GoatGrid';
import { useAuth } from '@/context/AuthContext';
import { useCart } from '@/context/CartContext';
import { apiFetch } from '@/lib/api';
import { useLiveRefresh } from '@/lib/useLiveRefresh';

export default function WishlistPage() {
  const { token } = useAuth();
  const { wishlistIds } = useCart();
  const [goats, setGoats] = useState(null);

  const load = useCallback(async () => {
    if (!token) return;

    try {
      const response = await apiFetch('/wishlist', { token });
      setGoats(response.data || []);
    } catch {
      setGoats([]);
    }
  }, [token]);

  /*
   * A saved goat can be sold, unpublished or repriced by staff while it sits
   * on this list. No interval: nobody watches a wishlist waiting for it to
   * change, they come back to it.
   */
  useLiveRefresh(load, { immediate: true, enabled: Boolean(token), intervalMs: 0 });

  /*
   * Unhearting a goat used to re-fetch the whole list to make it disappear.
   * The saved ids already live in the cart context and update immediately, so
   * filtering against them drops the card on the spot -- no request, and no
   * dependency on a value this fetch never reads.
   */
  const saved = goats?.filter((goat) => wishlistIds.includes(goat.id)) ?? null;

  if (saved === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (!saved.length) {
    return (
      <div className="panel">
        <div className="empty">
          <i className="bi bi-heart" />
          <h1 className="h5">Nothing saved yet</h1>
          <p>Tap the heart on any goat to keep it here for later.</p>
          <Link href="/shop" className="btn btn-brand px-4">Browse goats</Link>
        </div>
      </div>
    );
  }

  return (
    <div>
      <h1 className="h4 mb-4">Wishlist</h1>
      <GoatGrid goats={saved} columns={3} />
    </div>
  );
}
