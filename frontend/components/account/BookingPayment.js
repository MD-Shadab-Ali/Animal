'use client';

import { useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { ApiError, apiFetch } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { isGatewayMethod, payForBooking } from '@/lib/gateway';

/**
 * Paying for a room, after it has been booked.
 *
 * Two quite different things wear one panel here, and the difference is who
 * decides the money arrived. A gateway is asked and answers, so the guest is
 * handed over and the booking settles itself. A bank transfer is a claim: the
 * guest says they sent it, and nothing moves until somebody at the farm has
 * seen it on the statement.
 *
 * How much is never sent on a gateway payment and never recomputed here -- the
 * booking knows what is due today under the plan it was placed on.
 */
export default function BookingPayment({ booking, onDone }) {
  const { token } = useAuth();
  const settings = useSettings();

  const [amount, setAmount] = useState(booking.totals.due_now || booking.totals.balance_due);
  const [reference, setReference] = useState('');
  const [busy, setBusy] = useState(false);

  const method = booking.payment.method;
  const dueNow = booking.totals.due_now;

  // Nothing left to collect, or the stay is off.
  if (booking.payment.is_fully_paid || booking.status === 'cancelled') return null;

  // A claim already sitting with staff. The form would only invite a second
  // row for one transfer, and the server refuses it anyway.
  if (booking.payment.has_pending_claim) {
    return (
      <div className="panel">
        <h2 className="h6 mb-1">We are checking your payment</h2>
        <p className="text-soft small mb-0">
          You have told us about a payment on this booking. We will confirm it against the
          account shortly, and your room is held in the meantime.
        </p>
      </div>
    );
  }

  const throughGateway = async () => {
    setBusy(true);

    try {
      const result = await payForBooking(booking.booking_number, method, token);

      // An earlier attempt turned out to have gone through, so there is
      // nowhere to send them -- the booking has already moved on.
      if (result.settled) {
        toast.success('That payment already went through.');
        onDone?.();
        setBusy(false);
      }
      // Otherwise the browser is on its way to the provider; leave it be.
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'That payment could not be started.');
      setBusy(false);
    }
  };

  const claim = async (event) => {
    event.preventDefault();
    setBusy(true);

    try {
      await apiFetch(`/bookings/${booking.booking_number}/payments`, {
        method: 'POST',
        token,
        body: {
          method,
          amount: Number(amount),
          transaction_reference: reference || null,
        },
      });

      toast.success('Thank you — we will confirm it shortly.');
      onDone?.();
    } catch (error) {
      toast.error(error instanceof ApiError
        ? Object.values(error.errors || {}).flat()[0] || error.message
        : 'That could not be sent.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="panel">
      <h2 className="h6 mb-1">
        {booking.payment.awaiting_advance ? 'Pay the advance' : 'Pay for your stay'}
      </h2>

      <p className="text-soft small mb-3">
        {booking.payment.awaiting_advance
          ? `${formatMoney(dueNow, settings)} now holds the room. The rest is due when you arrive.`
          : `${formatMoney(dueNow, settings)} outstanding.`}
      </p>

      {isGatewayMethod(method) ? (
        <button type="button" className="btn btn-brand w-100" onClick={throughGateway} disabled={busy}>
          {busy ? 'Opening…' : `Pay ${formatMoney(dueNow, settings)}`}
        </button>
      ) : (
        <form onSubmit={claim}>
          {/* Where to send it. Filled in by the farm in the admin, so this
              panel never invents an account number. */}
          {booking.payment.payee && (
            <dl className="spec-list mb-3">
              {Object.entries(booking.payment.payee).map(([label, value]) => (
                <div key={label}>
                  <dt>{label.replace(/_/g, ' ')}</dt>
                  <dd>{value}</dd>
                </div>
              ))}
            </dl>
          )}

          <div className="mb-2">
            <label className="form-label small" htmlFor="payment-amount">How much did you send?</label>
            <input
              id="payment-amount"
              type="number"
              className="form-control"
              min="1"
              step="0.01"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              required
            />
          </div>

          <div className="mb-3">
            <label className="form-label small" htmlFor="payment-reference">Reference (optional)</label>
            <input
              id="payment-reference"
              type="text"
              className="form-control"
              value={reference}
              onChange={(event) => setReference(event.target.value)}
              placeholder="The transaction id from your bank"
            />
          </div>

          <button type="submit" className="btn btn-brand w-100" disabled={busy}>
            {busy ? 'Sending…' : 'I have sent it'}
          </button>

          <p className="text-soft small mt-2 mb-0">
            We will check it against the account and confirm your booking.
          </p>
        </form>
      )}
    </div>
  );
}
