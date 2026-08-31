'use client';

import { useCallback, useState } from 'react';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import { useLiveRefresh } from '@/lib/useLiveRefresh';
import PayoutPanel from '@/components/seller/PayoutPanel';

const sum = (rows, key) => rows.reduce((total, row) => total + Number(row[key] || 0), 0);

export default function SellerEarningsPage() {
  const { token } = useAuth();
  const settings = useSettings();

  const [earnings, setEarnings] = useState(null);
  const [payouts, setPayouts] = useState([]);

  // Bumped after a payout is requested or the payout details change, so the
  // balances, the history and the button state all reload together.

  const load = useCallback(async () => {
    if (!token) return;

    try {
      const [earningsResponse, payoutsResponse] = await Promise.all([
        apiFetch('/seller/earnings', { token }),
        apiFetch('/seller/payouts', { token }),
      ]);

      setEarnings(earningsResponse.data);
      setPayouts(payoutsResponse.data || []);
    } catch {
      setEarnings(false);
    }
  }, [token]);

  // A seller who has requested a payout is waiting on somebody in the admin
  // panel to approve and send it. This is the page they sit on to find out.
  useLiveRefresh(load, { immediate: true, enabled: Boolean(token) });

  if (earnings === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (earnings === false) {
    return <div className="panel"><p className="mb-0 text-soft">Could not load your earnings right now.</p></div>;
  }

  const { summary, lines, payout } = earnings;
  // What PayoutPanel calls after requesting a payout.
  const reload = load;

  return (
    <div className="d-grid gap-4">
      <h1 className="h4 mb-0">Earnings</h1>

      {/* One across on a phone: at 390px two of these cards left the
          right-hand pair clipped at the screen edge. */}
      <div className="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3">
        {[
          ['Ready to pay out', summary.unpaid, 'Delivered, awaiting payout'],
          ['Awaiting delivery', summary.pending, 'Sold but not earned yet'],
          ['Paid to you', summary.paid, 'Across all payouts'],
          ['Earned to date', summary.lifetime, `Delivered, after ${summary.commission_rate}% commission`],
        ].map(([label, value, hint]) => (
          <div className="col" key={label}>
            <div className="panel h-100">
              <div className="small text-soft">{label}</div>
              <div className="h4 text-brand fw-bold mb-1">{formatMoney(value, settings)}</div>
              <div className="small text-soft">{hint}</div>
            </div>
          </div>
        ))}
      </div>

      <PayoutPanel payout={payout} unpaid={summary.unpaid} onRequested={reload} />

      <div className="panel">
        <h2 className="h6 mb-3">Payouts</h2>

        {payouts.length === 0 ? (
          <p className="text-soft small mb-0">
            No payouts yet. We settle earnings once orders are delivered
            {summary.min_payout > 0 && ` and the balance reaches ${formatMoney(summary.min_payout, settings)}`}.
          </p>
        ) : (
          <div className="table-responsive">
            <table className="table align-middle mb-0">
              <thead>
                <tr className="small text-soft">
                  <th>Reference</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Sales</th>
                  <th>Status</th>
                  <th>Paid</th>
                </tr>
              </thead>
              <tbody>
                {payouts.map((entry) => (
                  <tr key={entry.reference}>
                    <td className="fw-semibold text-ink">{entry.reference}</td>
                    <td>{formatMoney(entry.amount, settings)}</td>
                    <td className="small text-soft">{entry.method_label || '—'}</td>
                    <td>{entry.items_count}</td>
                    <td>
                      <span className={`status-pill ${entry.status === 'paid' ? 'text-bg-success' : 'text-bg-warning'}`}>
                        {entry.status_label}
                      </span>
                    </td>
                    <td className="small text-soft">{entry.paid_at ? formatDate(entry.paid_at) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="panel">
        <h2 className="h6 mb-3">Every sale</h2>

        {lines.length === 0 ? (
          <p className="text-soft small mb-0">Nothing sold yet.</p>
        ) : (
          <div className="table-responsive">
            <table className="table align-middle mb-0">
              <thead>
                <tr className="small text-soft">
                  <th>Goat</th>
                  <th>Order</th>
                  <th className="text-end">Sold for</th>
                  <th className="text-end">Commission</th>
                  <th className="text-end">Delivery</th>
                  <th className="text-end">You earn</th>
                  <th>State</th>
                </tr>
              </thead>
              <tbody>
                {lines.map((line, index) => (
                  <tr key={`${line.order_number}-${index}`}>
                    <td className="text-ink">{line.goat}</td>
                    <td className="small text-soft">{line.order_number}</td>
                    <td className="text-end">{formatMoney(line.gross, settings)}</td>
                    <td className="text-end text-soft">−{formatMoney(line.commission, settings)}</td>
                    <td className="text-end">
                      {line.delivery > 0
                        ? <span className="text-brand">+{formatMoney(line.delivery, settings)}</span>
                        : <span className="text-soft">—</span>}
                    </td>
                    <td className="text-end fw-semibold text-brand">{formatMoney(line.earning, settings)}</td>
                    <td className="small">
                      {line.paid_out
                        ? <span className="text-success">Paid out</span>
                        : line.settled
                          ? <span className="text-warning">Awaiting payout</span>
                          : <span className="text-soft">Pending delivery</span>}
                    </td>
                  </tr>
                ))}
              </tbody>

              {/* A totals row under a single sale just repeats it, so only add
                  it once there is actually something to add up. */}
              {lines.length > 1 && (
                <tfoot>
                  <tr className="border-top">
                    <th colSpan={2} className="text-soft fw-normal">
                      Totals
                      <span className="d-block fw-normal" style={{ fontSize: '.75rem' }}>
                        {lines.length} sales
                      </span>
                    </th>
                    <th className="text-end">{formatMoney(sum(lines, 'gross'), settings)}</th>
                    <th className="text-end text-soft">−{formatMoney(sum(lines, 'commission'), settings)}</th>
                    <th className="text-end text-brand">+{formatMoney(sum(lines, 'delivery'), settings)}</th>
                    <th className="text-end text-brand">{formatMoney(sum(lines, 'earning'), settings)}</th>
                    <th />
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        )}

        <p className="small text-soft mt-3 mb-0">
          Delivery is earned once per order, on orders you deliver yourself. Commission is
          never taken on it.
        </p>
      </div>
    </div>
  );
}
