'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import GoatGrid from '@/components/goat/GoatGrid';
import { useAuth } from '@/context/AuthContext';
import { useCart } from '@/context/CartContext';
import { apiFetch } from '@/lib/api';

export default function WishlistPage() {
  const { token } = useAuth();
  const { wishlistIds } = useCart();
  const [goats, setGoats] = useState(null);

  // Re-fetch whenever the saved ids change, so removing a heart updates the grid.
  useEffect(() => {
    if (!token) return;

    apiFetch('/wishlist', { token })
      .then((response) => setGoats(response.data || []))
      .catch(() => setGoats([]));
  }, [token, wishlistIds.length]);

  if (goats === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (!goats.length) {
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
      <GoatGrid goats={goats} columns={3} />
    </div>
  );
}
