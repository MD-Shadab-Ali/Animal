'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { useAuth } from '@/context/AuthContext';
import { useCart } from '@/context/CartContext';
import { useSeller } from '@/context/SellerContext';
import { ADMIN_URL } from '@/lib/admin';
import MobileNav from './MobileNav';

export default function Header({ site }) {
  const { settings, menus } = site;
  const pathname = usePathname();
  const { isAuthenticated, user, logout, isStaff } = useAuth();
  const { itemCount, wishlistIds } = useCart();
  const { seller, isApproved } = useSeller();

  const navigation = menus?.header || [];

  // The masthead only casts a shadow once there is something beneath it to
  // cast onto, and gives back a little height while scrolling so more of the
  // goats stay on screen.
  //
  // Watched with an IntersectionObserver against a sentinel rather than a
  // scroll listener: a scroll handler fires on every frame of every scroll and
  // does its work on the main thread, which is what makes headers judder on a
  // mid-range Android. The observer fires twice, when the sentinel crosses.
  const sentinel = useRef(null);
  const [stuck, setStuck] = useState(false);

  useEffect(() => {
    const target = sentinel.current;

    if (!target) return undefined;

    const observer = new IntersectionObserver(
      ([entry]) => setStuck(!entry.isIntersecting),
      { rootMargin: '0px' }
    );

    observer.observe(target);

    return () => observer.disconnect();
  }, []);


  const isActive = (url) => {
    const path = url.split('?')[0];
    return path === '/' ? pathname === '/' : pathname.startsWith(path);
  };

  return (
    <>
      {/* Sits in the flow immediately above the sticky masthead, so it leaves
          the viewport exactly when the header starts overlapping content. */}
      <div ref={sentinel} aria-hidden="true" className="header__sentinel" />

        <header className={`header ${stuck ? 'is-stuck' : ''}`}>
        <div className="header__top">
          <div className="container">
            <div className="d-flex align-items-center gap-3">
              <Link href="/" className="brand flex-shrink-0">
                {settings.site_logo ? (
                  <img src={settings.site_logo} alt={settings.site_name} className="brand__logo" />
                ) : (
                  <>
                    <span className="brand__mark" aria-hidden="true"><i className="bi bi-flower3" /></span>
                    <span className="d-none d-sm-inline">{settings.site_name}</span>
                  </>
                )}
              </Link>

              {/* Sits where the search used to. One row, so the masthead is a
                  single band rather than a stack of strips. */}
              {navigation.length > 0 && (
                <nav
                  className="header__nav header__nav--inline d-none d-lg-flex flex-grow-1"
                  aria-label="Main"
                >
                  {navigation.map((item) => (
                    <Link
                      key={`${item.label}-${item.url}`}
                      href={item.url}
                      className={`navlink ${isActive(item.url) ? 'is-active' : ''}`}
                      target={item.new_tab ? '_blank' : undefined}
                    >
                      {item.label}
                    </Link>
                  ))}
                </nav>
              )}

              <div className="d-flex align-items-center gap-1 ms-auto">
                <Link
                  href="/account/wishlist"
                  className="icon-btn d-none d-sm-inline-grid"
                  aria-label={`Wishlist, ${wishlistIds.length} saved`}
                >
                  <i className="bi bi-heart" aria-hidden="true" />
                  {wishlistIds.length > 0 && <span className="icon-btn__count">{wishlistIds.length}</span>}
                </Link>

                <Link href="/cart" className="icon-btn" aria-label={`Cart, ${itemCount} items`}>
                  <i className="bi bi-bag" aria-hidden="true" />
                  {itemCount > 0 && <span className="icon-btn__count">{itemCount}</span>}
                </Link>

                {isAuthenticated ? (
                  <div className="dropdown d-none d-lg-block">
                    <button
                      className="icon-btn"
                      data-bs-toggle="dropdown"
                      aria-expanded="false"
                      aria-label="Your account"
                    >
                      <i className="bi bi-person" aria-hidden="true" />
                    </button>
                    <ul className="dropdown-menu dropdown-menu-end shadow border-0 mt-2" style={{ borderRadius: 'var(--radius-lg)' }}>
                      <li className="dropdown-header text-ink fw-semibold">{user?.name}</li>

                      {/* Staff arrive here like anyone else; this is the only
                          signpost back to the panel they actually work in. */}
                      {isStaff && (
                        <>
                          <li>
                            <a
                              className="dropdown-item fw-semibold text-brand"
                              href={ADMIN_URL}
                              target="_blank"
                              rel="noopener noreferrer"
                            >
                              <i className="bi bi-speedometer2 me-2" aria-hidden="true" />
                              Admin panel
                            </a>
                          </li>
                          <li><hr className="dropdown-divider" /></li>
                        </>
                      )}

                      {seller && (
                        <>
                          <li>
                            <Link className="dropdown-item fw-semibold text-brand" href="/seller">
                              <i className="bi bi-shop me-2" aria-hidden="true" />
                              {isApproved ? 'Seller dashboard' : 'Seller application'}
                            </Link>
                          </li>
                          <li><hr className="dropdown-divider" /></li>
                        </>
                      )}

                      <li><Link className="dropdown-item" href="/account">My orders</Link></li>
                      <li><Link className="dropdown-item" href="/account/wishlist">Wishlist</Link></li>
                      <li><Link className="dropdown-item" href="/account/addresses">Addresses</Link></li>
                      <li><Link className="dropdown-item" href="/account/profile">Profile</Link></li>
                      <li><hr className="dropdown-divider" /></li>
                      <li><button className="dropdown-item text-danger" onClick={logout}>Sign out</button></li>
                    </ul>
                  </div>
                ) : (
                  <Link href="/login" className="btn btn-brand btn-sm d-none d-lg-inline-flex ms-2">
                    Sign in
                  </Link>
                )}

                <button
                  className="icon-btn d-lg-none"
                  type="button"
                  data-bs-toggle="offcanvas"
                  data-bs-target="#mobileNav"
                  aria-controls="mobileNav"
                  aria-label="Open menu"
                >
                  <i className="bi bi-list" aria-hidden="true" />
                </button>
              </div>
            </div>

          </div>
        </div>

      </header>

      {/*
       * Deliberately a sibling of the masthead, not a child of it.
       * The header carries a backdrop-filter, and any of transform, filter or
       * backdrop-filter on an ancestor makes that ancestor the containing block
       * for position: fixed descendants. Nested inside, the drawer's top:0 and
       * bottom:0 resolved against the 73px header instead of the viewport, so
       * the menu opened as a sliver with ten links crammed into a 32px scroll.
       */}
      <MobileNav
        navigation={navigation}
        settings={settings}
        isAuthenticated={isAuthenticated}
        user={user}
        isStaff={isStaff}
        seller={seller}
        isApprovedSeller={isApproved}
        onLogout={logout}
      />
    </>
  );
}
