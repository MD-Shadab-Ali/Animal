'use client';

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { apiFetch, ApiError } from '@/lib/api';
import { useAuth } from './AuthContext';

const CartContext = createContext(null);

const emptyCart = {
  items: [],
  coupon: null,
  totals: { subtotal: 0, discount: 0, total: 0, total_quantity: 0 },
};

export function CartProvider({ children }) {
  const { token, isAuthenticated } = useAuth();
  const [cart, setCart] = useState(emptyCart);
  const [wishlistIds, setWishlistIds] = useState([]);
  const [loading, setLoading] = useState(false);

  const refresh = useCallback(async () => {
    if (!token) {
      setCart(emptyCart);
      setWishlistIds([]);
      return;
    }

    try {
      const [cartResponse, wishlistResponse] = await Promise.all([
        apiFetch('/cart', { token }),
        apiFetch('/wishlist/ids', { token }),
      ]);

      setCart(cartResponse.data);
      setWishlistIds(wishlistResponse.data || []);
    } catch {
      setCart(emptyCart);
    }
  }, [token]);

  // Load the cart whenever the signed-in customer changes.
  useEffect(() => {
    let active = true;

    async function load() {
      if (!token) {
        if (active) {
          setCart(emptyCart);
          setWishlistIds([]);
        }
        return;
      }

      try {
        const [cartResponse, wishlistResponse] = await Promise.all([
          apiFetch('/cart', { token }),
          apiFetch('/wishlist/ids', { token }),
        ]);

        if (!active) return;

        setCart(cartResponse.data);
        setWishlistIds(wishlistResponse.data || []);
      } catch {
        if (active) setCart(emptyCart);
      }
    }

    load();

    return () => { active = false; };
  }, [token]);

  /** Every mutating call needs a signed-in customer — guests cannot order. */
  const requireAuth = useCallback(() => {
    if (!isAuthenticated) {
      toast.error('Please sign in first.');
      return false;
    }
    return true;
  }, [isAuthenticated]);

  const run = useCallback(async (request, successMessage) => {
    setLoading(true);
    try {
      const response = await request();
      if (response?.data) setCart(response.data);
      if (successMessage !== false) toast.success(successMessage || response?.message || 'Done');
      return response;
    } catch (error) {
      const message = error instanceof ApiError
        ? Object.values(error.errors)[0]?.[0] || error.message
        : 'Something went wrong.';
      toast.error(message);
      throw error;
    } finally {
      setLoading(false);
    }
  }, []);

  const addItem = useCallback(async (goatId, quantity = 1) => {
    if (!requireAuth()) return null;
    return run(() => apiFetch('/cart', { method: 'POST', token, body: { goat_id: goatId, quantity } }));
  }, [requireAuth, run, token]);

  const updateItem = useCallback((itemId, quantity) =>
    run(() => apiFetch(`/cart/items/${itemId}`, { method: 'PUT', token, body: { quantity } })),
  [run, token]);

  const removeItem = useCallback((itemId) =>
    run(() => apiFetch(`/cart/items/${itemId}`, { method: 'DELETE', token })),
  [run, token]);

  const clearCart = useCallback(() =>
    run(() => apiFetch('/cart', { method: 'DELETE', token })),
  [run, token]);

  const applyCoupon = useCallback((code) =>
    run(() => apiFetch('/cart/coupon', { method: 'POST', token, body: { code } })),
  [run, token]);

  const removeCoupon = useCallback(() =>
    run(() => apiFetch('/cart/coupon', { method: 'DELETE', token })),
  [run, token]);

  const toggleWishlist = useCallback(async (goatId) => {
    if (!requireAuth()) return;

    try {
      const response = await apiFetch('/wishlist/toggle', { method: 'POST', token, body: { goat_id: goatId } });

      setWishlistIds((current) => response.data.in_wishlist
        ? [...current, goatId]
        : current.filter((id) => id !== goatId));

      toast.success(response.message);
    } catch {
      toast.error('Could not update your wishlist.');
    }
  }, [requireAuth, token]);

  // Lets a product card ask "is this one already in the cart?" so the button can
  // reflect it instead of always offering to add again.
  const cartItemFor = useCallback(
    (goatId) => (cart?.items || []).find((item) => item.goat?.id === goatId) || null,
    [cart]
  );

  const value = useMemo(() => ({
    cart,
    loading,
    wishlistIds,
    itemCount: cart?.totals?.total_quantity || 0,
    isInWishlist: (goatId) => wishlistIds.includes(goatId),
    cartItemFor,
    isInCart: (goatId) => Boolean(cartItemFor(goatId)),
    addItem,
    updateItem,
    removeItem,
    clearCart,
    applyCoupon,
    removeCoupon,
    toggleWishlist,
    refresh,
  }), [cart, loading, wishlistIds, cartItemFor, addItem, updateItem, removeItem, clearCart, applyCoupon, removeCoupon, toggleWishlist, refresh]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const context = useContext(CartContext);
  if (!context) throw new Error('useCart must be used inside <CartProvider>');
  return context;
}
