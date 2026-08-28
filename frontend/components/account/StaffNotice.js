'use client';

import { useAuth } from '@/context/AuthContext';
import { ADMIN_URL } from '@/lib/admin';

/**
 * Shown across the account area to a staff member.
 *
 * A staff account has no orders, no wishlist and no addresses of its own, so
 * every page here greets them with an empty state written for a shopper who
 * has not bought anything yet. That reads like a fault. This says plainly
 * which account they are on and where the work they came for actually lives.
 */
export default function StaffNotice() {
  const { isStaff, user } = useAuth();

  if (!isStaff) return null;

  return (
    <div className="panel d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
      <div>
        <strong className="text-ink d-block">
          <i className="bi bi-shield-check text-brand me-2" aria-hidden="true" />
          Signed in as {user?.role === 'admin' ? 'an administrator' : 'staff'}
        </strong>
        <span className="small text-soft">
          This is your own shopping account. The shop itself is managed in the admin panel.
        </span>
      </div>

      <a
        className="btn btn-brand px-4 flex-shrink-0"
        href={ADMIN_URL}
        target="_blank"
        rel="noopener noreferrer"
      >
        Open admin panel
      </a>
    </div>
  );
}
