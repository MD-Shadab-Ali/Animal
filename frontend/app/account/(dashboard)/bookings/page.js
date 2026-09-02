'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useCallback, useState } from 'react';
import Pagination from '@/components/ui/Pagination';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { useLiveRefresh } from '@/lib/useLiveRefresh';
import { formatDate, formatMoney } from '@/lib/format';

// The same colours the admin panel uses for these statuses, so staff and guest
// are not looking at two different pictures of one stay.
const STATUS_COLORS = {
  placed: 'text-bg-warning',
  confirmed: 'text-bg-info',
  checked_in: 'text-bg-primary',
  checked_out: 'text-bg-success',
  cancelled: 'text-bg-danger',
};

export default function BookingsPage() {
  const { token } = useAuth();
  const settings = useSettings();
  const searchParams = useSearchParams();
  const page = searchParams.get('page') || '1';

  const [payload, setPayload] = useState(null);

  const load = useCallback(async () => {
    if (!token) return;

    try {
      setPayload(await apiFetch(`/bookings?page=${page}`, { token }));
    } catch {
      setPayload({ data: [] });
    }
  }, [token, page]);

  // Staff confirm payments and check people in, so this list goes stale while
  // a tab sits open. Coming back to it is when it gets read again.
  useLiveRefresh(load, { immediate: true, enabled: Boolean(token) });

  if (payload === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  const bookings = payload.data || [];

  if (!bookings.length) {
    return (
      <div className="panel text-center py-5">
        <i className="bi bi-house-door d-block mb-2" style={{ fontSize: '2rem' }} aria-hidden="true" />
        <h1 className="h5 mb-1">No stays yet</h1>
        <p className="text-soft small mb-3">
          Rooms at the farm, for when getting home the same day is a stretch.
        </p>
        <Link href="/homestay" className="btn btn-brand btn-sm">See the rooms</Link>
      </div>
    );
  }

  return (
    <>
      <div className="d-flex flex-column gap-3">
        {bookings.map((booking) => (
          <Link
            key={booking.booking_number}
            href={`/account/bookings/${booking.booking_number}`}
            className="panel booking-row"
          >
            <div className="booking-row__media">
              {booking.room.thumbnail
                ? <img src={booking.room.thumbnail} alt={booking.room.name} loading="lazy" />
                : <i className="bi bi-house-door" aria-hidden="true" />}
            </div>

            <div className="min-w-0 flex-grow-1">
              <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span className="fw-semibold text-ink">{booking.room.name}</span>
                <span className={`badge ${STATUS_COLORS[booking.status] || 'text-bg-secondary'}`}>
                  {booking.status_label}
                </span>
              </div>

              <div className="small text-soft">
                {formatDate(booking.stay.check_in)} – {formatDate(booking.stay.check_out)}
                {' · '}
                {booking.stay.nights} night{booking.stay.nights === 1 ? '' : 's'}
                {' · '}
                {booking.stay.guests} guest{booking.stay.guests === 1 ? '' : 's'}
              </div>

              <div className="small text-soft">{booking.booking_number}</div>
            </div>

            <div className="text-end flex-shrink-0">
              <div className="fw-semibold text-ink">
                {formatMoney(booking.totals.total, settings)}
              </div>
              {/* What is owed *today*, which on an advance plan is not the same
                  as the balance -- and is the figure worth chasing. */}
              {booking.totals.due_now > 0.009 && booking.status !== 'cancelled' && (
                <div className="small text-warning">
                  {formatMoney(booking.totals.due_now, settings)} due
                </div>
              )}
            </div>
          </Link>
        ))}
      </div>

      <Pagination meta={payload.meta} basePath="/account/bookings" />
    </>
  );
}
