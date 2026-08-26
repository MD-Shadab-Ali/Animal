'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import ListingForm from '@/components/seller/ListingForm';
import ListingStatusPill from '@/components/seller/ListingStatusPill';
import { useAuth } from '@/context/AuthContext';
import { apiFetch } from '@/lib/api';

export default function EditListingPage() {
  const { id } = useParams();
  const { token } = useAuth();
  const [listing, setListing] = useState(null);

  useEffect(() => {
    if (!token) return;

    apiFetch(`/seller/listings/${id}`, { token })
      .then((response) => setListing(response.data))
      .catch(() => setListing(false));
  }, [token, id]);

  if (listing === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (listing === false) {
    return (
      <div className="panel">
        <div className="empty">
          <i className="bi bi-question-circle empty__icon" aria-hidden="true" />
          <h1 className="h5">Listing not found</h1>
          <Link href="/seller/listings" className="btn btn-brand px-4">Back to my listings</Link>
        </div>
      </div>
    );
  }

  return (
    <div>
      <nav className="crumbs mb-2" aria-label="Breadcrumb">
        <Link href="/seller/listings">My listings</Link>
        <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
        <span className="text-ink">{listing.name}</span>
      </nav>

      <div className="d-flex flex-wrap align-items-center gap-3 mb-4">
        <h1 className="h4 mb-0">Edit listing</h1>
        <ListingStatusPill status={listing.state} />
      </div>

      {listing.approval_status === 'rejected' && listing.rejection_reason && (
        <div className="alert alert-warning">
          <strong>Changes requested:</strong> {listing.rejection_reason}
        </div>
      )}

      {!listing.is_editable ? (
        <div className="panel">
          <p className="mb-2">
            {listing.state === 'sold'
              ? 'This goat has sold, so its listing is kept exactly as it was bought.'
              : `This listing is ${listing.state === 'pending' ? 'waiting for review' : 'live in the shop'},
                 so it is locked to keep the moderated version and the public version the same.`}
          </p>
          <p className="text-soft small mb-3">Contact us if something needs changing.</p>
          <Link href="/contact" className="btn btn-quiet">Contact us</Link>
        </div>
      ) : (
        <ListingForm listing={listing} />
      )}
    </div>
  );
}
