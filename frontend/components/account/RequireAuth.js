'use client';

import Link from 'next/link';
import { useAuth } from '@/context/AuthContext';

/** Client-side gate for the account area — the API enforces it too. */
export default function RequireAuth({ children }) {
  const { isAuthenticated, loading } = useAuth();

  if (loading) {
    return (
      <div className="text-center py-5">
        <span className="spinner-border text-brand" />
      </div>
    );
  }

  if (!isAuthenticated) {
    return (
      <div className="empty">
        <i className="bi bi-person-lock" />
        <h1 className="h4">Please sign in</h1>
        <p>This page is part of your account.</p>
        <Link href="/login" className="btn btn-brand px-4">
          Sign in
        </Link>
      </div>
    );
  }

  return children;
}
