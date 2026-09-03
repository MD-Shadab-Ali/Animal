'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useCallback, useState } from 'react';
import Pagination from '@/components/ui/Pagination';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { useLiveRefresh } from '@/lib/useLiveRefresh';
import { formatDateTime, formatMoney } from '@/lib/format';

// The three states a payment can be in, in the colours the order page already
// gives them.
const STATUS_COLORS = {
  pending: 'text-bg-warning',
  confirmed: 'text-bg-success',
  rejected: 'text-bg-danger',
};

export default function PaymentsPage() {
  const { token } = useAuth();
  const settings = useSettings();
  const searchParams = useSearchParams();
  const page = searchParams.get('page') || '1';

  const [payload, setPayload] = useState(null);

  const load = useCallback(async () => {
    if (!token) return;

    try {
      setPayload(await apiFetch(`/payments?page=${page}`, { token }));
    } catch {
      setPayload({ data: [] });
    }
  }, [token, page]);

  // A row here does move on its own: money sent sits at "Awaiting check"
  // until staff agree it landed, and that happens in the admin panel. Same
  // reasoning as the orders list -- coming back to the tab is when it gets
  // read again. Changing page re-runs it too, since `load` is keyed to it.
  useLiveRefresh(load, { immediate: true, enabled: Boolean(token) });

  if (payload === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  const rows = payload.data || [];
  const paid = Number(payload.summary?.paid || 0);
  const refunded = Number(payload.summary?.refunded || 0);

  if (!rows.length) {
    return (
      <div className="panel">
        <div className="empty">
          <i className="bi bi-credit-card" />
          <h1 className="h5">No payments yet</h1>
          <p>Every payment you make on an order shows up here, with its reference and what it was for.</p>
          <Link href="/shop" className="btn btn-brand px-4">Browse goats</Link>
        </div>
      </div>
    );
  }

  return (
    <div>
      <h1 className="h4 mb-1">Payments</h1>
      <p className="small text-soft mb-4">
        Every payment and refund on your orders, newest first.
      </p>

      {/* Only money that has actually been checked and cleared. A claim staff
          have not looked at yet is not something you have paid, and saying so
          in the headline is what keeps this page from arguing with the order. */}
      <div className="panel mb-4 d-flex flex-wrap gap-4">
        <div>
          <div className="small text-soft">Paid to date</div>
          <div className="h4 mb-0 text-brand fw-bold">{formatMoney(paid, settings)}</div>
        </div>

        {refunded > 0 && (
          <div>
            <div className="small text-soft">Refunded to you</div>
            <div className="h4 mb-0 text-success fw-bold">{formatMoney(refunded, settings)}</div>
          </div>
        )}
      </div>

      <div className="panel panel--flush">
        <div className="table-responsive">
          <table className="table align-middle mb-0">
            <thead>
              <tr className="small text-soft">
                <th scope="col" className="ps-3">When</th>
                <th scope="col">Order</th>
                <th scope="col">Method</th>
                <th scope="col" className="text-end">Amount</th>
                <th scope="col" className="text-end pe-3">State</th>
              </tr>
            </thead>

            <tbody>
              {rows.map((row) => {
                const isRefund = row.type === 'refund';

                return (
                  <tr key={row.id}>
                    <td className="ps-3">
                      <div className="small">{row.paid_at ? formatDateTime(row.paid_at) : '—'}</div>
                      <div className="small text-soft">{row.type_label}</div>
                    </td>

                    <td>
                      {/* The whole point of a ledger row: one click back to
                          what the money was actually for -- which is a goat
                          order for some of these and a room for others, so the
                          link follows the money rather than assuming. */}
                      {row.order_number && (
                        <Link href={`/account/orders/${row.order_number}`} className="fw-semibold text-body">
                          {row.order_number}
                        </Link>
                      )}

                      {row.booking_number && (
                        <Link href={`/account/bookings/${row.booking_number}`} className="fw-semibold text-body">
                          {row.booking_number}
                        </Link>
                      )}

                      {!row.order_number && !row.booking_number && <span className="text-soft">—</span>}

                      {/* Gated on there being an order: goats_summary answers
                          "—" for a stay, and a dash under a room name reads as
                          something having failed to load. */}
                      {row.order_number && row.goats && (
                        <div className="small text-soft">{row.goats}</div>
                      )}

                      {row.stay && (
                        <div className="small text-soft">
                          <i className="bi bi-house-door me-1" aria-hidden="true" />
                          {row.stay}
                        </div>
                      )}
                    </td>

                    <td>
                      <div className="small">{row.method_label}</div>
                      {/* What to quote when asking us about this one. */}
                      <div className="small text-soft">{row.transaction_reference || row.reference}</div>
                    </td>

                    <td className={`text-end fw-semibold ${isRefund ? 'text-success' : ''} ${row.status === 'rejected' ? 'text-soft' : ''}`}>
                      {isRefund && '−'}{formatMoney(Math.abs(row.amount), settings)}
                    </td>

                    <td className="text-end pe-3">
                      <span className={`status-pill ${STATUS_COLORS[row.status] || 'text-bg-secondary'}`}>
                        {row.status_label}
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

      <Pagination meta={payload.meta} basePath="/account/payments" />
    </div>
  );
}
