'use client';

import Link from 'next/link';
import { useCallback, useState } from 'react';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { useLiveRefresh } from '@/lib/useLiveRefresh';

export default function SellerDashboard() {
  const { token } = useAuth();
  const settings = useSettings();
  const [stats, setStats] = useState(null);

  const load = useCallback(async () => {
    if (!token) return;

    try {
      const response = await apiFetch('/seller/dashboard', { token });
      setStats(response.data);
    } catch {
      setStats(false);
    }
  }, [token]);

  // Every number here is downstream of something staff do: confirming an
  // order, approving a listing, paying a payout.
  useLiveRefresh(load, { immediate: true, enabled: Boolean(token) });

  if (stats === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (stats === false) {
    return <div className="panel"><p className="mb-0 text-soft">Could not load your dashboard right now.</p></div>;
  }

  const { listings, sales, earnings } = stats;

  const tiles = [
    ['Live listings', listings.live, 'bi-shop', 'Visible in the shop now', ''],
    ['Awaiting review', listings.pending, 'bi-hourglass-split', 'With our team', ''],
    ['Goats sold', listings.sold, 'bi-check2-circle', `${sales.units} unit${sales.units === 1 ? '' : 's'}`, ''],
    // The only tile that ever asks for anything. It says so when it has
    // something to ask, and stays quiet at zero like the rest.
    ['Needs attention', listings.rejected, 'bi-exclamation-triangle', 'Sent back to you',
      listings.rejected > 0 ? 'stat-tile--warn' : ''],
  ];

  return (
    <div className="d-grid gap-4">
      <div className="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <h1 className="h4 mb-0">Overview</h1>
        <Link href="/seller/listings/new" className="btn btn-cta">
          <i className="bi bi-plus-lg" aria-hidden="true" /> List a goat
        </Link>
      </div>

      {/* One across on a phone: at 390px two of these cards left the
          right-hand pair clipped at the screen edge. */}
      <div className="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3">
        {tiles.map(([label, value, icon, hint, tone]) => (
          <div className="col" key={label}>
            {/* Icon beside the figure rather than stacked above it: four of
                these read as one dashboard row, not as four separate cards. */}
            <div className={`stat-tile ${tone}`}>
              <span className="stat-tile__icon" aria-hidden="true">
                <i className={`bi ${icon}`} />
              </span>
              <div className="min-w-0">
                <div className="stat-tile__value">{value}</div>
                <div className="stat-tile__label">{label}</div>
                <div className="stat-tile__hint">{hint}</div>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="row g-4">
        <div className="col-md-7">
          <div className="panel h-100">
            <h2 className="h6 mb-3">Earnings</h2>

            {/* The one figure a seller opens this page for. It was row one of
                a five-row list, weighted the same as the commission rate. */}
            <div className="mb-3 pb-3 border-bottom">
              <div className="small text-soft">Ready to be paid out</div>
              <div className="price-now">{formatMoney(earnings.unpaid, settings)}</div>
            </div>

            <dl className="row mb-0">
              <dt className="col-7 fw-normal text-soft">
                Awaiting delivery
                <span className="d-block small">not earned yet</span>
              </dt>
              <dd className="col-5 text-end mb-2">{formatMoney(earnings.pending, settings)}</dd>

              <dt className="col-7 fw-normal text-soft">Already paid to you</dt>
              <dd className="col-5 text-end mb-2">{formatMoney(earnings.paid, settings)}</dd>

              <dt className="col-7 fw-normal text-soft">Earned to date</dt>
              <dd className="col-5 text-end mb-2">{formatMoney(earnings.lifetime, settings)}</dd>

              <dt className="col-7 fw-normal text-soft">Commission</dt>
              <dd className="col-5 text-end mb-0">{earnings.commission_rate}% per sale</dd>
            </dl>

            <p className="small text-soft mt-3 mb-0">
              A sale only counts as earned once the order is delivered. Until then it sits
              under &ldquo;awaiting delivery&rdquo;.
              {earnings.min_payout > 0 && ` Minimum payout is ${formatMoney(earnings.min_payout, settings)}.`}
            </p>

            <Link href="/seller/earnings" className="btn btn-quiet btn-sm mt-3">
              See the breakdown
            </Link>
          </div>
        </div>

        <div className="col-md-5">
          <div className="panel h-100">
            <h2 className="h6 mb-3">Sales</h2>

            <dl className="row mb-0">
              <dt className="col-7 fw-normal text-soft">Orders</dt>
              <dd className="col-5 text-end mb-2">{sales.orders}</dd>

              <dt className="col-7 fw-normal text-soft">Gross revenue</dt>
              <dd className="col-5 text-end mb-0">{formatMoney(sales.revenue, settings)}</dd>
            </dl>

            <Link href="/seller/orders" className="btn btn-quiet btn-sm mt-3">View sales</Link>
          </div>
        </div>
      </div>

      {listings.rejected > 0 && (
        <div className="alert alert-warning mb-0">
          <i className="bi bi-exclamation-triangle-fill me-2" aria-hidden="true" />
          {listings.rejected} listing{listings.rejected === 1 ? '' : 's'} came back with changes requested.{' '}
          <Link href="/seller/listings?state=rejected" className="fw-semibold">Fix them</Link>
        </div>
      )}
    </div>
  );
}
