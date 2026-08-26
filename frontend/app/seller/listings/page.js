'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense, useCallback, useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import ListingStatusPill from '@/components/seller/ListingStatusPill';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { formatMoney } from '@/lib/format';

// Listing states, not approval statuses — "Live" and "Sold" are both approved,
// and a seller needs to tell them apart more than anything else on this screen.
const FILTERS = [
  ['', 'All'],
  ['draft', 'Drafts'],
  ['pending', 'Awaiting review'],
  ['live', 'Live'],
  ['sold', 'Sold'],
  ['rejected', 'Changes needed'],
];

function ListingsInner() {
  const { token } = useAuth();
  const settings = useSettings();
  const searchParams = useSearchParams();

  const [listings, setListings] = useState(null);
  // `?approval_status=` is still honoured so older links keep working.
  const [filter, setFilter] = useState(
    searchParams.get('state') || searchParams.get('approval_status') || ''
  );
  const [busy, setBusy] = useState(null);
  const [deleting, setDeleting] = useState(null);

  const load = useCallback(async (status) => {
    if (!token) return;

    const query = status ? `?state=${status}` : '';

    try {
      const response = await apiFetch(`/seller/listings${query}`, { token });
      setListings(response.data || []);
    } catch {
      setListings([]);
    }
  }, [token]);

  useEffect(() => {
    let active = true;

    async function fetchListings() {
      if (!token) return;

      const query = filter ? `?state=${filter}` : '';

      try {
        const response = await apiFetch(`/seller/listings${query}`, { token });
        if (active) setListings(response.data || []);
      } catch {
        if (active) setListings([]);
      }
    }

    fetchListings();

    return () => { active = false; };
  }, [token, filter]);

  const submit = async (listing) => {
    setBusy(listing.id);
    try {
      const response = await apiFetch(`/seller/listings/${listing.id}/submit`, { method: 'POST', token });
      toast.success(response.message);
      load(filter);
    } catch (error) {
      toast.error(error.errors?.listing?.[0] || error.message);
    } finally {
      setBusy(null);
    }
  };

  const remove = async () => {
    const listing = deleting;
    setDeleting(null);
    setBusy(listing.id);
    try {
      const response = await apiFetch(`/seller/listings/${listing.id}`, { method: 'DELETE', token });
      toast.success(response.message);
      load(filter);
    } catch (error) {
      toast.error(error.errors?.listing?.[0] || error.message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <div>
      <ConfirmDialog
        open={Boolean(deleting)}
        title={deleting ? `Delete "${deleting.name}"?` : 'Delete this listing?'}
        lines={['This removes the draft for good. Listings that have already sold cannot be deleted.']}
        confirmLabel="Delete it"
        cancelLabel="Keep it"
        busy={Boolean(busy)}
        onConfirm={remove}
        onCancel={() => setDeleting(null)}
      />

      <div className="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 className="h4 mb-0">My listings</h1>
        <Link href="/seller/listings/new" className="btn btn-cta">
          <i className="bi bi-plus-lg" aria-hidden="true" /> List a goat
        </Link>
      </div>

      <div className="d-flex flex-wrap gap-2 mb-4">
        {FILTERS.map(([value, label]) => (
          <button
            key={value || 'all'}
            type="button"
            className={`chip ${filter === value ? 'is-active' : ''}`}
            onClick={() => setFilter(value)}
          >
            {label}
          </button>
        ))}
      </div>

      {listings === null && <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>}

      {listings?.length === 0 && (
        <div className="panel">
          <div className="empty">
            <i className="bi bi-clipboard-plus empty__icon" aria-hidden="true" />
            <h2 className="h5">Nothing here yet</h2>
            <p>Create a listing and send it for review — most are checked within a day.</p>
            <Link href="/seller/listings/new" className="btn btn-cta px-4">List your first goat</Link>
          </div>
        </div>
      )}

      <div className="d-grid gap-3">
        {(listings || []).map((listing) => (
          <div className="panel" key={listing.id}>
            <div className="d-flex flex-wrap gap-3">
              <div className="gallery__thumb" style={{ width: 96, aspectRatio: '4 / 3', flexShrink: 0 }}>
                {listing.thumbnail
                  ? <img src={listing.thumbnail} alt="" />
                  : <div className="card-goat__empty"><i className="bi bi-image" aria-hidden="true" /></div>}
              </div>

              <div className="flex-grow-1" style={{ minWidth: 200 }}>
                <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                  <strong className="text-ink">{listing.name}</strong>
                  <ListingStatusPill status={listing.state} />
                </div>

                <div className="small text-soft mb-2">
                  {listing.sku}
                  {listing.breed && ` · ${listing.breed}`}
                  {listing.weight_kg && ` · ${listing.weight_kg} kg`}
                </div>

                {listing.approval_status === 'rejected' && listing.rejection_reason && (
                  <div className="alert alert-warning py-2 px-3 small mb-2">
                    <strong>Changes requested:</strong> {listing.rejection_reason}
                  </div>
                )}

                <div className="fw-bold text-brand">{formatMoney(listing.sale_price || listing.price, settings)}</div>
              </div>

              <div className="d-flex flex-column gap-2 justify-content-center">
                {listing.is_editable && (
                  <>
                    <Link href={`/seller/listings/${listing.id}`} className="btn btn-quiet btn-sm">Edit</Link>
                    <button
                      className="btn btn-brand btn-sm"
                      onClick={() => submit(listing)}
                      disabled={busy === listing.id}
                    >
                      Send for review
                    </button>
                  </>
                )}

                {listing.is_live && (
                  <Link href={`/goats/${listing.slug}`} className="btn btn-quiet btn-sm">View in shop</Link>
                )}

                {listing.approval_status === 'draft' && (
                  <button
                    className="btn btn-link btn-sm text-danger"
                    onClick={() => setDeleting(listing)}
                    disabled={busy === listing.id}
                  >
                    Delete
                  </button>
                )}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

export default function SellerListingsPage() {
  return (
    <Suspense fallback={<div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>}>
      <ListingsInner />
    </Suspense>
  );
}
