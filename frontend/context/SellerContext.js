'use client';

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { apiFetch } from '@/lib/api';
import { useAuth } from './AuthContext';

const SellerContext = createContext(null);

/**
 * Holds the signed-in user's seller account, if they have one. `status` tells
 * the UI whether to show the apply prompt, a "under review" notice, or the
 * full seller area.
 */
export function SellerProvider({ children }) {
  const { token, isAuthenticated } = useAuth();
  const [seller, setSeller] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    async function load() {
      if (!token) {
        if (active) {
          setSeller(null);
          setLoading(false);
        }
        return;
      }

      try {
        const response = await apiFetch('/seller/profile', { token });
        if (active) setSeller(response.data ?? null);
      } catch {
        if (active) setSeller(null);
      } finally {
        if (active) setLoading(false);
      }
    }

    load();
    return () => { active = false; };
  }, [token]);

  const refresh = useCallback(async () => {
    if (!token) return null;

    const response = await apiFetch('/seller/profile', { token });
    setSeller(response.data ?? null);
    return response.data ?? null;
  }, [token]);

  const value = useMemo(() => ({
    seller,
    loading,
    refresh,
    setSeller,
    hasApplied: Boolean(seller),
    isApproved: seller?.status === 'approved',
    canApply: isAuthenticated && !seller,
  }), [seller, loading, refresh, isAuthenticated]);

  return <SellerContext.Provider value={value}>{children}</SellerContext.Provider>;
}

export function useSeller() {
  const context = useContext(SellerContext);
  if (!context) throw new Error('useSeller must be used inside <SellerProvider>');
  return context;
}
