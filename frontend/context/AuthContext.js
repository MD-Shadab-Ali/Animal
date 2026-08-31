'use client';

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { ApiError, apiFetch } from '@/lib/api';
import { useLiveRefresh } from '@/lib/useLiveRefresh';

const TOKEN_KEY = 'gh_token';
const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(null);
  const [loading, setLoading] = useState(true);

  // Restore the session from localStorage on first paint.
  useEffect(() => {
    let active = true;

    async function restoreSession() {
      const stored = typeof window !== 'undefined' ? localStorage.getItem(TOKEN_KEY) : null;

      try {
        if (stored) {
          const response = await apiFetch('/auth/me', { token: stored });

          if (active) {
            setToken(stored);
            setUser(response.data);
          }
        }
      } catch (error) {
        /*
         * Only a refusal proves the token is no good.
         *
         * This used to throw away the session on *any* failure, which included
         * the one failure that is not the token's fault: a hard refresh aborts
         * the request in flight, the rejection lands here while the old page is
         * still tearing down, and the token was deleted on the way out -- so the
         * reload came back signed out. A blip, a 500 or a restarted API server
         * did the same thing. Keep it and let the next load ask again.
         */
        if (error instanceof ApiError && error.status === 401) {
          localStorage.removeItem(TOKEN_KEY);
        }
      } finally {
        if (active) setLoading(false);
      }
    }

    restoreSession();

    return () => { active = false; };
  }, []);

  const persist = useCallback((nextToken, nextUser) => {
    localStorage.setItem(TOKEN_KEY, nextToken);
    setToken(nextToken);
    setUser(nextUser);
  }, []);

  const login = useCallback(async (credentials) => {
    const response = await apiFetch('/auth/login', { method: 'POST', body: credentials });
    persist(response.data.token, response.data.user);
    return response.data.user;
  }, [persist]);

  /**
   * Signing up no longer signs you in.
   *
   * The account is created unverified and carries no token: the address has to
   * be proved first, or a made-up one would be as good as a real one. The
   * token arrives from verifyEmail() instead.
   */
  const register = useCallback(async (payload) => {
    const response = await apiFetch('/auth/register', { method: 'POST', body: payload });
    return response.data;
  }, []);

  /**
   * Signing in through Google, from either the sign-in or the sign-up page.
   *
   * One call for both, because the API decides which it was: an address it
   * already knows is signed in, one it does not is created and signed in. What
   * comes back is the same token and user an ordinary sign-in returns, stored
   * the same way, so nothing downstream can tell a Google account apart from
   * any other.
   */
  const loginWithGoogle = useCallback(async (credential) => {
    const response = await apiFetch('/auth/google', {
      method: 'POST',
      body: { credential },
    });

    persist(response.data.token, response.data.user);
    return response.data.user;
  }, [persist]);

  const verifyEmail = useCallback(async (email, code) => {
    const response = await apiFetch('/auth/verify-email', {
      method: 'POST',
      body: { email, code },
    });

    persist(response.data.token, response.data.user);
    return response.data.user;
  }, [persist]);

  const resendVerification = useCallback((email) =>
    apiFetch('/auth/resend-verification', { method: 'POST', body: { email } }),
  []);

  const logout = useCallback(async () => {
    try {
      if (token) await apiFetch('/auth/logout', { method: 'POST', token });
    } catch {
      // The token may already be gone server-side; clearing locally is enough.
    }

    localStorage.removeItem(TOKEN_KEY);
    setToken(null);
    setUser(null);
  }, [token]);

  const refreshUser = useCallback(async () => {
    if (!token) return null;
    const response = await apiFetch('/auth/me', { token });
    setUser(response.data);
    return response.data;
  }, [token]);

  /*
   * Staff can rename an account, change what it is allowed to do, or switch it
   * off. Re-asking when the tab comes back keeps the header, the seller
   * prompts and the profile page honest about it.
   */
  useLiveRefresh(refreshUser, { enabled: Boolean(token), intervalMs: 0 });

  const value = useMemo(
    () => ({
      user,
      token,
      loading,
      isAuthenticated: Boolean(token && user),

      // Admin, manager or staff. Decides what this app offers a signed-in
      // person -- the way through to the admin panel, and the seller prompts
      // that do not apply to them. It never decides whether a request is
      // allowed: the API settles that on its own, and this flag comes from the
      // API in the first place.
      isStaff: Boolean(user?.is_staff),

      login,
      loginWithGoogle,
      register,
      verifyEmail,
      resendVerification,
      logout,
      refreshUser,
    }),
    [user, token, loading, login, loginWithGoogle, register, verifyEmail, resendVerification, logout, refreshUser]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>');
  return context;
}
