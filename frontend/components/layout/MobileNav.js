'use client';

import { AnimatePresence, m } from 'motion/react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, useRef } from 'react';
import { ADMIN_URL } from '@/lib/admin';
import { TRANSITION, drawerPanel, scrim } from '@/lib/motion';

/**
 * The mobile navigation drawer.
 *
 * This used to be a Bootstrap offcanvas, and closing it was a fight. Bootstrap
 * dismisses on click with, verbatim:
 *
 *   if (['A', 'AREA'].includes(this.tagName)) event.preventDefault()
 *
 * Every link in here is a next/link, which renders an anchor, so
 * data-bs-dismiss on one meant Bootstrap cancelled the click before the router
 * ever saw it: the drawer slid shut and the page never changed. The workaround
 * was to reach into the DOM and programmatically press the close button
 * instead -- which then had its own race with the navigation.
 *
 * Owning the open state in React removes the whole class of problem. A link is
 * a link again, and closing is setState.
 */
export default function MobileNav({
  open, onClose,
  navigation, settings, isAuthenticated, user, isStaff, seller, isApprovedSeller, onLogout,
}) {
  const pathname = usePathname();
  const closeButton = useRef(null);
  const returnFocusTo = useRef(null);

  /*
   * Held in a ref so the effects below can depend on `open` and `pathname`
   * alone. An inline arrow for onClose would otherwise be a new function on
   * every render, re-running the route effect each time and slamming the
   * drawer shut the instant it opened.
   */
  const requestClose = useRef(onClose);

  /*
   * Kept current in an effect rather than assigned during render, which React
   * forbids. It is declared first on purpose: effects run in the order they
   * are written, so by the time the ones below read this ref after a commit,
   * it already holds the callback from that same render.
   */
  useEffect(() => {
    requestClose.current = onClose;
  });

  // Covers the ways out that are not a click on a link in here: the back
  // button, or anything else that changes the route while the drawer is open.
  useEffect(() => {
    requestClose.current?.();
  }, [pathname]);

  useEffect(() => {
    if (!open) return undefined;

    const onKeyDown = (event) => {
      if (event.key === 'Escape') requestClose.current?.();
    };

    // Remember where focus came from -- almost always the hamburger -- so
    // closing puts it back rather than dropping it on the body.
    returnFocusTo.current = document.activeElement;

    document.addEventListener('keydown', onKeyDown);
    document.body.classList.add('modal-open');
    closeButton.current?.focus();

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.classList.remove('modal-open');
      returnFocusTo.current?.focus?.();
    };
  }, [open]);

  /*
   * A drawer left open while the window grows into the desktop layout would
   * otherwise sit over a nav bar that already shows every one of these links
   * -- and, worse, hold the scroll lock on a page with no visible way to
   * release it.
   */
  useEffect(() => {
    if (!open) return undefined;

    const wide = window.matchMedia('(min-width: 992px)');
    const sync = () => { if (wide.matches) requestClose.current?.(); };

    sync();
    wide.addEventListener('change', sync);

    return () => wide.removeEventListener('change', sync);
  }, [open]);

  const close = () => onClose?.();

  return (
    <AnimatePresence mode="sync">
      {open && (
        <m.div
          key="drawer-scrim"
          className="drawer-backdrop"
          variants={scrim}
          initial="hidden"
          animate="shown"
          exit="hidden"
          transition={TRANSITION.fast}
          onClick={close}
        />
      )}

      {open && (
        <m.aside
          key="drawer-panel"
          className="drawer"
          role="dialog"
          aria-modal="true"
          aria-labelledby="mobileNavLabel"
          variants={drawerPanel}
          initial="hidden"
          animate="shown"
          exit="hidden"
          transition={TRANSITION.normal}
        >
          <div className="drawer__head">
            <h2 className="h6 mb-0" id="mobileNavLabel">{settings.site_name}</h2>
            <button
              type="button"
              ref={closeButton}
              className="btn-close"
              aria-label="Close"
              onClick={close}
            />
          </div>

          <div className="drawer__body">
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
        </m.aside>
      )}
    </AnimatePresence>
  );
}
