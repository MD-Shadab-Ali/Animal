'use client';

import Link from 'next/link';
import { ADMIN_URL } from '@/lib/admin';

export default function MobileNav({
  navigation, settings, isAuthenticated, user, isStaff, seller, isApprovedSeller, onLogout,
}) {
  return (
    <div className="offcanvas offcanvas-end" tabIndex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
      <div className="offcanvas-header border-bottom">
        <h2 className="offcanvas-title h6 mb-0" id="mobileNavLabel">{settings.site_name}</h2>
        <button type="button" className="btn-close" data-bs-dismiss="offcanvas" aria-label="Close" />
      </div>

      <div className="offcanvas-body d-flex flex-column">
        <nav className="d-grid gap-1 mb-4" aria-label="Mobile">
          {navigation.map((item) => (
            <Link
              key={`m-${item.label}-${item.url}`}
              href={item.url}
              className="navlink"
              data-bs-dismiss="offcanvas"
            >
              {item.label}
            </Link>
          ))}
        </nav>

        <div className="d-grid gap-2 mt-auto">
          {isAuthenticated ? (
            <>
              <div className="text-soft small">Signed in as <strong className="text-ink">{user?.name}</strong></div>

              {isStaff && (
                <a
                  className="btn btn-brand"
                  href={ADMIN_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                  data-bs-dismiss="offcanvas"
                >
                  <i className="bi bi-speedometer2 me-1" aria-hidden="true" />
                  Admin panel
                </a>
              )}

              {seller && (
                <Link href="/seller" className="btn btn-brand" data-bs-dismiss="offcanvas">
                  <i className="bi bi-shop me-1" aria-hidden="true" />
                  {isApprovedSeller ? 'Seller dashboard' : 'Seller application'}
                </Link>
              )}

              <Link href="/account" className="btn btn-quiet" data-bs-dismiss="offcanvas">My orders</Link>
              <Link href="/account/wishlist" className="btn btn-quiet" data-bs-dismiss="offcanvas">Wishlist</Link>
              <Link href="/account/addresses" className="btn btn-quiet" data-bs-dismiss="offcanvas">Addresses</Link>
              <button className="btn btn-link text-danger" onClick={onLogout}>Sign out</button>
            </>
          ) : (
            <>
              <Link href="/login" className="btn btn-brand" data-bs-dismiss="offcanvas">Sign in</Link>
              <Link href="/register" className="btn btn-quiet" data-bs-dismiss="offcanvas">Create account</Link>
            </>
          )}

          {settings.contact_phone && (
            <a href={`tel:${settings.contact_phone}`} className="btn btn-outline-brand mt-2">
              <i className="bi bi-telephone me-1" aria-hidden="true" /> {settings.contact_phone}
            </a>
          )}
        </div>
      </div>
    </div>
  );
}
