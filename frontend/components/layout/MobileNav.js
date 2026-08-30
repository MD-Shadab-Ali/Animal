'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useCallback, useEffect } from 'react';
import { ADMIN_URL } from '@/lib/admin';

/**
 * Closes the drawer without going through data-bs-dismiss.
 *
 * Bootstrap's dismiss handler does this, verbatim:
 *
 *   if (['A', 'AREA'].includes(this.tagName)) event.preventDefault()
 *
 * Every link in here is a next/link, which renders an anchor, so putting
 * data-bs-dismiss on one meant Bootstrap cancelled the click before the router
 * ever saw it: the drawer slid shut and the page never changed. Closing it
 * ourselves leaves the click alone.
 */
function useDrawer() {
  const close = useCallback(() => {
    const el = document.getElementById('mobileNav');

    if (!el || !el.classList.contains('show')) {
      return;
    }

    // Press the header's close button rather than reaching for the Offcanvas
    // class. Bootstrap only cancels the default action for A and AREA, so a
    // button is safe to trigger -- and this closes on the spot, where waiting
    // on an import() sometimes lost the race with the navigation and left the
    // drawer sitting open on the new page.
    el.querySelector('button[data-bs-dismiss="offcanvas"]')?.click();
  }, []);

  // Covers the ways out that are not a click on a link in here: the back
  // button, or anything else that changes the route while the drawer is open.
  const pathname = usePathname();

  useEffect(() => {
    close();
  }, [pathname, close]);

  return close;
}

export default function MobileNav({
  navigation, settings, isAuthenticated, user, isStaff, seller, isApprovedSeller, onLogout,
}) {
  const close = useDrawer();

  return (
    <div className="offcanvas offcanvas-end" tabIndex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
      <div className="offcanvas-header border-bottom">
        <h2 className="offcanvas-title h6 mb-0" id="mobileNavLabel">{settings.site_name}</h2>
        {/* A button, not a link, so Bootstrap's own dismiss is safe here. */}
        <button type="button" className="btn-close" data-bs-dismiss="offcanvas" aria-label="Close" />
      </div>

      <div className="offcanvas-body d-flex flex-column">
        <nav className="d-grid gap-1 mb-4" aria-label="Mobile">
          {navigation.map((item) => (
            <Link
              key={`m-${item.label}-${item.url}`}
              href={item.url}
              className="navlink"
              onClick={close}
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
                  onClick={close}
                >
                  <i className="bi bi-speedometer2 me-1" aria-hidden="true" />
                  Admin panel
                </a>
              )}

              {seller && (
                <Link href="/seller" className="btn btn-brand" onClick={close}>
                  <i className="bi bi-shop me-1" aria-hidden="true" />
                  {isApprovedSeller ? 'Seller dashboard' : 'Seller application'}
                </Link>
              )}

              <Link href="/account" className="btn btn-quiet" onClick={close}>My orders</Link>
              <Link href="/account/wishlist" className="btn btn-quiet" onClick={close}>Wishlist</Link>
              <Link href="/account/addresses" className="btn btn-quiet" onClick={close}>Addresses</Link>
              <button
                className="btn btn-link text-danger"
                onClick={() => { close(); onLogout(); }}
              >
                Sign out
              </button>
            </>
          ) : (
            <>
              <Link href="/login" className="btn btn-brand" onClick={close}>Sign in</Link>
              <Link href="/register" className="btn btn-quiet" onClick={close}>Create account</Link>
            </>
          )}

          {settings.contact_phone && (
            <a href={`tel:${settings.contact_phone}`} className="btn btn-outline-brand mt-2" onClick={close}>
              <i className="bi bi-telephone me-1" aria-hidden="true" /> {settings.contact_phone}
            </a>
          )}
        </div>
      </div>
    </div>
  );
}
