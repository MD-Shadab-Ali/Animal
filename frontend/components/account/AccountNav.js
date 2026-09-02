'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuth } from '@/context/AuthContext';
import { ADMIN_URL } from '@/lib/admin';

const LINKS = [
  ['/account', 'My orders', 'bi-box-seam'],
  ['/account/bookings', 'Your stays', 'bi-house-door'],
  ['/account/payments', 'Payments', 'bi-credit-card'],
  ['/account/wishlist', 'Wishlist', 'bi-heart'],
  ['/account/addresses', 'Addresses', 'bi-geo-alt'],
  ['/account/profile', 'Profile', 'bi-person'],
];

/**
 * The account's own masthead: who is signed in, and the way between their
 * pages.
 *
 * It used to be a panel down the left, which cost a quarter of the width on
 * every screen to show five links that fit comfortably on one line. Large
 * storefronts run this across the top for exactly that reason -- the orders
 * are the page, and the navigation is a strip above them.
 */
export default function AccountNav() {
  const pathname = usePathname();
  const { user, logout, isStaff } = useAuth();

  return (
    <div className="mb-4">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div className="d-flex align-items-center gap-3 min-w-0">
          <div className="avatar" style={{ width: 44, height: 44 }}>
            {user?.avatar ? <img src={user.avatar} alt={user.name} /> : <span>{user?.name?.charAt(0)}</span>}
          </div>
          <div className="min-w-0">
            <div className="fw-semibold text-ink text-truncate">{user?.name}</div>
            <div className="small text-soft text-truncate">{user?.email}</div>
          </div>
        </div>

        <div className="d-flex align-items-center gap-2">
          {/* Staff arrive here like any other buyer; this is their way back to
              the panel they actually work in. */}
          {isStaff && (
            <a
              className="btn btn-quiet btn-sm"
              href={ADMIN_URL}
              target="_blank"
              rel="noopener noreferrer"
            >
              <i className="bi bi-speedometer2 me-1" aria-hidden="true" />Admin panel
            </a>
          )}

          {/* Deliberately not a tab. Signing out is not somewhere you go. */}
          <button className="btn btn-quiet btn-sm text-danger" onClick={logout}>
            <i className="bi bi-box-arrow-right me-1" aria-hidden="true" />Sign out
          </button>
        </div>
      </div>

      {/* The same tab rail the goat page uses, so the site keeps one idea of
          what a row of sections looks like. It scrolls sideways rather than
          wrapping: five wrapped tabs read as two broken rows. */}
      <nav className="nav nav-tabs tab-rail flex-nowrap" aria-label="Account">
        {LINKS.map(([href, label, icon]) => (
          <Link
            key={href}
            href={href}
            className={`nav-link ${pathname === href ? 'active' : ''}`}
            aria-current={pathname === href ? 'page' : undefined}
          >
            <i className={`bi ${icon} me-2`} aria-hidden="true" />{label}
          </Link>
        ))}
      </nav>
    </div>
  );
}
