'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuth } from '@/context/AuthContext';
import { useCart } from '@/context/CartContext';
import { useSeller } from '@/context/SellerContext';
import HeaderSearch from './HeaderSearch';
import MobileNav from './MobileNav';

export default function Header({ site }) {
  const { settings, menus } = site;
  const pathname = usePathname();
  const { isAuthenticated, user, logout } = useAuth();
  const { itemCount, wishlistIds } = useCart();
  const { seller, isApproved } = useSeller();

  const navigation = menus?.header || [];

  const isActive = (url) => {
    const path = url.split('?')[0];
    return path === '/' ? pathname === '/' : pathname.startsWith(path);
  };

  return (
    <header className="header">
      <div className="header__top">
        <div className="container">
          <div className="d-flex align-items-center gap-3">
            <Link href="/" className="brand flex-shrink-0">
              {settings.site_logo ? (
                <img src={settings.site_logo} alt={settings.site_name} style={{ maxHeight: 40 }} />
              ) : (
                <>
                  <span className="brand__mark" aria-hidden="true"><i className="bi bi-flower3" /></span>
                  <span className="d-none d-sm-inline">{settings.site_name}</span>
                </>
              )}
            </Link>

            {/* The search bar is the primary action on a marketplace. */}
            <div className="flex-grow-1 d-none d-lg-block mx-2" style={{ maxWidth: 560 }}>
              <HeaderSearch />
            </div>

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

          {/* Search drops to its own row on small screens rather than disappearing. */}
          <div className="d-lg-none mt-2">
            <HeaderSearch />
          </div>
        </div>
      </div>

      {navigation.length > 0 && (
        <nav className="header__nav d-none d-lg-block" aria-label="Main">
          <div className="container">
            <div className="header__nav-inner">
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
            </div>
          </div>
        </nav>
      )}

      <MobileNav
        navigation={navigation}
        settings={settings}
        isAuthenticated={isAuthenticated}
        user={user}
        seller={seller}
        isApprovedSeller={isApproved}
        onLogout={logout}
      />
    </header>
  );
}
