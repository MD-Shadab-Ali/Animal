'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useSeller } from '@/context/SellerContext';

const LINKS = [
  ['/seller', 'Overview', 'bi-speedometer2'],
  ['/seller/listings', 'My listings', 'bi-list-check'],
  ['/seller/orders', 'Sales', 'bi-box-seam'],
  ['/seller/earnings', 'Earnings', 'bi-cash-coin'],
];

/**
 * The farm's masthead: whose shop this is, and the way between its pages.
 *
 * Same shape as the buyer's account bar, for the same reason: four links do
 * not need a quarter of every screen, and the numbers a seller comes here to
 * read are wider than nine columns.
 */
export default function SellerNav() {
  const pathname = usePathname();
  const { seller } = useSeller();

  return (
    <div className="mb-4">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div className="d-flex align-items-center gap-3 min-w-0">
          <span className="avatar" style={{ width: 44, height: 44 }}>
            {seller?.logo ? <img src={seller.logo} alt="" /> : seller?.farm_name?.charAt(0)}
          </span>
          <div className="min-w-0">
            <div className="fw-semibold text-ink text-truncate">{seller?.farm_name}</div>
            <div className="small text-soft">{seller?.commission_rate}% commission</div>
          </div>
        </div>

        {/* Not a tab. Switching to the buyer side is leaving this dashboard,
            not moving around inside it -- which is why it had a rule above it
            in the old sidebar too. */}
        <Link href="/account" className="btn btn-quiet btn-sm">
          <i className="bi bi-person me-1" aria-hidden="true" />Buyer account
        </Link>
      </div>

      <nav className="nav nav-tabs tab-rail flex-nowrap" aria-label="Seller">
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
