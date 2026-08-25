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

export default function SellerNav() {
  const pathname = usePathname();
  const { seller } = useSeller();

  return (
    <div className="panel">
      <div className="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
        <span className="avatar" style={{ width: 48, height: 48 }}>
          {seller?.logo ? <img src={seller.logo} alt="" /> : seller?.farm_name?.charAt(0)}
        </span>
        <div className="overflow-hidden">
          <div className="fw-semibold text-truncate text-ink">{seller?.farm_name}</div>
          <div className="small text-soft">{seller?.commission_rate}% commission</div>
        </div>
      </div>

      <nav className="d-grid gap-1" aria-label="Seller">
        {LINKS.map(([href, label, icon]) => (
          <Link
            key={href}
            href={href}
            className={`navlink ${pathname === href ? 'is-active' : ''}`}
          >
            <i className={`bi ${icon} me-2`} aria-hidden="true" />{label}
          </Link>
        ))}

        <hr className="my-2" />

        <Link href="/account" className="navlink">
          <i className="bi bi-person me-2" aria-hidden="true" />Buyer account
        </Link>
      </nav>
    </div>
  );
}
