'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuth } from '@/context/AuthContext';

const LINKS = [
  ['/account', 'My orders', 'bi-box-seam'],
  ['/account/wishlist', 'Wishlist', 'bi-heart'],
  ['/account/addresses', 'Addresses', 'bi-geo-alt'],
  ['/account/profile', 'Profile', 'bi-person'],
];

export default function AccountNav() {
  const pathname = usePathname();
  const { user, logout } = useAuth();

  return (
    <div className="panel">
      <div className="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
        <div className="avatar" style={{ width: 48, height: 48 }}>
          {user?.avatar ? <img src={user.avatar} alt={user.name} /> : <span>{user?.name?.charAt(0)}</span>}
        </div>
        <div className="overflow-hidden">
          <div className="fw-semibold text-truncate">{user?.name}</div>
          <div className="small text-soft text-truncate">{user?.email}</div>
        </div>
      </div>

      <nav className="d-grid gap-1">
        {LINKS.map(([href, label, icon]) => (
          <Link
            key={href}
            href={href}
            className={`navlink ${pathname === href ? 'is-active' : ''}`}
          >
            <i className={`bi ${icon} me-2`} />{label}
          </Link>
        ))}

        <button className="navlink text-start text-danger border-0 bg-transparent w-100" onClick={logout}>
          <i className="bi bi-box-arrow-right me-2" />Sign out
        </button>
      </nav>
    </div>
  );
}
