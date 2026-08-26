'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { formatMoney } from '@/lib/format';

export default function SellerDashboard() {
  const { token } = useAuth();
  const settings = useSettings();
  const [stats, setStats] = useState(null);

  useEffect(() => {
    if (!token) return;

    apiFetch('/seller/dashboard', { token })
      .then((response) => setStats(response.data))
      .catch(() => setStats(false));
  }, [token]);

  if (stats === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (stats === false) {
    return <div className="panel"><p className="mb-0 text-soft">Could not load your dashboard right now.</p></div>;
  }

  const { listings, sales, earnings } = stats;

  const tiles = [
    ['Live listings', listings.live, 'bi-shop', 'Visible in the shop now'],
    ['Awaiting review', listings.pending, 'bi-hourglass-split', 'With our team'],
    ['Goats sold', listings.sold, 'bi-check2-circle', `${sales.units} unit${sales.units === 1 ? '' : 's'}`],
    ['Needs attention', listings.rejected, 'bi-exclamation-triangle', 'Sent back to you'],
  ];

  return (
    <div className="d-grid gap-4">
      <div className="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <h1 className="h4 mb-0">Overview</h1>
        <Link href="/seller/listings/new" className="btn btn-cta">
          <i className="bi bi-plus-lg" aria-hidden="true" /> List a goat
        </Link>
      </div>

      <div className="row row-cols-2 row-cols-lg-4 g-3">
        {tiles.map(([label, value, icon, hint]) => (
          <div className="col" key={label}>
            <div className="panel h-100">
              <span className="step__icon mb-2" style={{ width: 40, height: 40, fontSize: '1.05rem', margin: 0 }}>
                <i className={`bi ${icon}`} aria-hidden="true" />
              </span>
              <div className="stat-block text-start mt-2">
                <b style={{ fontSize: '1.75rem' }}>{value}</b>
                <span className="d-block fw-semibold text-ink small">{label}</span>
                <span className="d-block small text-soft">{hint}</span>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="row g-4">
        <div className="col-md-7">
          <div className="panel h-100">
            <h2 className="h6 mb-3">Earnings</h2>

            <dl className="row mb-0">
              <dt className="col-7 fw-normal text-soft">Ready to be paid out</dt>
              <dd className="col-5 text-end fw-bold text-brand h5 mb-2">
                {formatMoney(earnings.unpaid, settings)}
              </dd>

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
