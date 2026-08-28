'use client';

import Link from 'next/link';
import { useAuth } from '@/context/AuthContext';
import { useSeller } from '@/context/SellerContext';
import { ADMIN_URL } from '@/lib/admin';

/**
 * Gate for the seller area. Each state gets its own explanation rather than a
 * blanket "access denied", so a pending applicant knows to wait and a rejected
 * one knows why. The API enforces the same rules.
 */
export default function RequireSeller({ children }) {
  const { isAuthenticated, loading: authLoading, isStaff } = useAuth();
  const { seller, loading } = useSeller();

  if (authLoading || loading) {
    return <div className="text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (!isAuthenticated) {
    return (
      <div className="empty">
        <i className="bi bi-person-lock empty__icon" aria-hidden="true" />
        <h1 className="h4">Sign in to manage your listings</h1>
        <Link href="/login" className="btn btn-brand px-4">
          Sign in
        </Link>
      </div>
    );
  }

  // Checked before the generic "not selling yet" state below, which would
  // otherwise send a staff member off to an application they cannot make.
  if (isStaff) {
    return (
      <div className="empty">
        <i className="bi bi-shield-check empty__icon" aria-hidden="true" />
        <h1 className="h4">This area is for sellers</h1>
        <p>Staff accounts manage listings, orders and payouts from the admin panel.</p>
        <a
          className="btn btn-brand px-4"
          href={ADMIN_URL}
          target="_blank"
          rel="noopener noreferrer"
        >
          Open admin panel
        </a>
      </div>
    );
  }

  if (!seller) {
    return (
      <div className="empty">
        <i className="bi bi-shop empty__icon" aria-hidden="true" />
        <h1 className="h4">You are not selling yet</h1>
        <p>Apply once and you can list goats for sale on the marketplace.</p>
        <Link href="/sell" className="btn btn-cta px-4">Start selling</Link>
      </div>
    );
  }

  if (seller.status === 'pending') {
    return (
      <div className="empty">
        <i className="bi bi-hourglass-split empty__icon" aria-hidden="true" />
        <h1 className="h4">Your application is being reviewed</h1>
        <p className="mb-1">We are checking the details for <strong>{seller.farm_name}</strong>.</p>
        <p>You will get an email as soon as it is decided — usually within a day.</p>
        <Link href="/shop" className="btn btn-quiet px-4">Browse the shop meanwhile</Link>
      </div>
    );
  }

  if (seller.status === 'suspended' || seller.status === 'rejected') {
    return (
      <div className="empty">
        <i className="bi bi-exclamation-octagon empty__icon" aria-hidden="true" />
        <h1 className="h4">
          {seller.status === 'suspended' ? 'Your seller account is suspended' : 'Application not approved'}
        </h1>
        {seller.review_note
          ? <p className="mb-3"><strong className="text-ink">Reason:</strong> {seller.review_note}</p>
          : <p>Please contact us and we will explain.</p>}
        <Link href="/contact" className="btn btn-brand px-4">Contact us</Link>
      </div>
    );
  }

  return children;
}
