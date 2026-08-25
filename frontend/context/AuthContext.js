'use client';

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { apiFetch } from '@/lib/api';

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
      } catch {
        // The stored token expired or was revoked.
        localStorage.removeItem(TOKEN_KEY);
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

  const register = useCallback(async (payload) => {
    const response = await apiFetch('/auth/register', { method: 'POST', body: payload });
    persist(response.data.token, response.data.user);
    return response.data.user;
  }, [persist]);

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

  const value = useMemo(
    () => ({ user, token, loading, isAuthenticated: Boolean(token && user), login, register, logout, refreshUser }),
    [user, token, loading, login, register, logout, refreshUser]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>');
  return context;
}
