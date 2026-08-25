'use client';

import Link from 'next/link';
import ListingForm from '@/components/seller/ListingForm';

export default function NewListingPage() {
  return (
    <div>
      <nav className="crumbs mb-2" aria-label="Breadcrumb">
        <Link href="/seller/listings">My listings</Link>
        <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
        <span className="text-ink">New listing</span>
      </nav>

      <h1 className="h4 mb-4">List a goat</h1>

      <ListingForm />
    </div>
  );
}
