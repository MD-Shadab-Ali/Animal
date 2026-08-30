'use client';

import { useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch, toFormData } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import { payThroughGateway } from '@/lib/gateway';

const STATUS_STYLE = {
  pending: 'text-bg-warning',
  confirmed: 'text-bg-success',
  rejected: 'text-bg-danger',
};

// Keyed by what the server says it is asking for, so the panel matches the plan
// the buyer chose at checkout rather than guessing from an amount.
const HEADINGS = {
  advance: 'Pay your advance',
  balance: 'Pay the balance',
  full: 'Pay for this order',
};

/**
 * How a buyer actually pays.
 *
 * The account to send to is whatever an admin filled in against the payment
 * method, so nothing here is hardcoded. Submitting is a declaration, not a
 * receipt — staff check it against the account before the order moves.
 */
export default function OrderPayment({ order, onPaid }) {
  const { token } = useAuth();
  const settings = useSettings();

  const payment = order.payment || {};
  const methods = payment.methods || [];
  const history = payment.history || [];

  const [method, setMethod] = useState(methods[0]?.code || '');
  // Default to what is actually owed today — the advance while one is
  // outstanding, the whole balance otherwise.
  const [amount, setAmount] = useState(payment.amount_due_now ?? payment.balance_due ?? '');
  const [reference, setReference] = useState('');
  const [note, setNote] = useState('');
  const [proof, setProof] = useState(null);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [starting, setStarting] = useState(false);

  const chosen = methods.find((entry) => entry.code === method);

  /**
   * Hand the buyer to the provider. There is no form to fill in afterwards:
   * whether the money arrived is settled between our server and theirs.
   */
  const startGateway = async () => {
    setStarting(true);
    setErrors({});

    try {
      const result = await payThroughGateway(order.order_number, method, token);

      // Only returns at all when there was nothing left to pay -- otherwise
      // the browser has already left for the provider.
      if (result.settled) {
        toast.success('That payment had already gone through.');
        onPaid?.();
      }
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.method?.[0] || error.message || 'Could not open the payment page.');
      setStarting(false);
    }
  };

  const submit = async (event) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});

    try {
      const response = await apiFetch(`/orders/${order.order_number}/payments`, {
        method: 'POST',
        token,
        body: toFormData({
          method,
          amount,
          transaction_reference: reference,
          note,
          proof,
        }),
      });

      toast.success(response.message);
      setReference('');
      setNote('');
      setProof(null);
      onPaid?.();
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.amount?.[0] || error.message || 'Could not submit your payment.');
    } finally {
      setSaving(false);
    }
  };

  // Paid everything that was owed up front. The rest is the rider's to
  // collect, so there is nothing to do here but say so.
  if (payment.settled_until_delivery) {
    return (
      <div className="panel">
        <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
          <h2 className="h6 mb-0">
            <i className="bi bi-check-circle-fill me-1 text-success" aria-hidden="true" />
            Payment received
          </h2>
          <span className="fw-bold text-brand">
            {formatMoney(payment.balance_due, settings)} on delivery
          </span>
        </div>

        {/* Never name a payment method here. The balance can be settled on any
            rail the shop accepts once the order is on its way — telling a buyer
            to have cash ready both presumes their choice and contradicts the
            "pay for this order" panel they will be shown at dispatch. */}
        <p className="text-soft small mb-0">
          We have your {formatMoney(order.totals.paid, settings)}. Nothing more is needed now —
          the remaining {formatMoney(payment.balance_due, settings)} is due when your goat is
          delivered
          {methods.length > 0
            ? ', and you can pay it here as soon as it is on its way, or settle it with the driver.'
            : '.'}
        </p>

        {history.length > 0 && (
          <div className="mt-4 pt-3 border-top">
            <h3 className="h6 mb-3">What you have paid</h3>
            <PaymentHistory history={history} settings={settings} />
          </div>
        )}
      </div>
    );
  }

  // Told us about a payment already. The form steps aside until staff have
  // ruled on it, so the same money is never submitted twice.
  if (payment.awaiting_check) {
    return (
      <div className="panel">
        <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
          <h2 className="h6 mb-0">
            <i className="bi bi-hourglass-split me-1 text-warning" aria-hidden="true" />
            Checking your payment
          </h2>
          <span className="fw-bold text-brand">
            {formatMoney(payment.submitted_amount, settings)} sent
          </span>
        </div>

        <p className="text-soft small mb-0">
          Thank you — we have your {formatMoney(payment.submitted_amount, settings)} on record and
          are checking it against our account. You will get an email as soon as it is confirmed,
          and there is nothing more to do in the meantime.
        </p>

        {history.length > 0 && (
          <div className="mt-4 pt-3 border-top">
            <h3 className="h6 mb-3">What you have sent</h3>
            <PaymentHistory history={history} settings={settings} />
          </div>
        )}
      </div>
    );
  }

  // Owed, but nobody has set up an account to receive it yet. Say so — a blank
  // space where the payment panel should be just reads as a broken page.
  if (payment.awaiting_setup) {
    return (
      <div className="panel">
        <h2 className="h6 mb-2">Paying for this order</h2>
        <p className="text-soft small mb-0">
          <i className="bi bi-telephone me-1" aria-hidden="true" />
          {formatMoney(payment.amount_due_now, settings)} is due. We will call you to arrange
          payment — the account details are not set up online yet.
        </p>
      </div>
    );
  }

  // Not due yet, already settled, or cancelled — show the record only.
  if (!payment.can_pay_now) {
    if (!history.length) return null;

    return (
      <div className="panel">
        <h2 className="h6 mb-3">Payments</h2>
        <PaymentHistory history={history} settings={settings} />
      </div>
    );
  }

  return (
    <div className="panel">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
        <h2 className="h6 mb-0">
          {HEADINGS[payment.due_kind] || HEADINGS.full}
        </h2>
        <span className="fw-bold text-brand">
          {formatMoney(payment.amount_due_now, settings)} due now
        </span>
      </div>

      {/* Driven by what the server says it is asking for, never inferred here.
          This used to read `awaiting_advance`, which only means "the up-front
          money has not arrived" — and a pay-in-full order sets its up-front
          amount to the whole total, so it announced "Pay your advance … the
          remaining Rs 0 is due when it arrives" on a full payment. */}
      <p className="text-soft small mb-4">
        {payment.due_kind === 'advance' && (
          <>
            You chose to pay an advance of{' '}
            {formatMoney(payment.advance_required, settings)} to reserve your goat — the
            remaining {formatMoney(payment.balance_due - payment.amount_due_now, settings)}{' '}
            is due when it arrives.{' '}
          </>
        )}

        {payment.due_kind === 'balance' && (
          <>
            You have paid {formatMoney(order.totals.paid, settings)} so far. This settles the
            order in full.{' '}
          </>
        )}

        {payment.due_kind === 'full' && (
          <>
            You chose to pay in full up front, so this covers the whole order — nothing is
            left to pay on delivery.{' '}
          </>
        )}

        {chosen?.is_gateway
          ? 'Pay with the button below and we will know the moment it clears — there is nothing to send us afterwards.'
          : 'Send the money using any of the accounts below, then tell us about it. We check every payment by hand before the goat goes out.'}
      </p>

      <div className="row g-2 mb-4">
        {methods.map((entry) => (
          <div className="col-sm-6 col-lg-4" key={entry.code}>
            <button
              type="button"
              onClick={() => setMethod(entry.code)}
              className={`panel w-100 h-100 text-start ${method === entry.code ? 'border-brand' : ''}`}
              style={{ cursor: 'pointer', borderWidth: method === entry.code ? 2 : 1 }}
            >
              <div className="d-flex align-items-center gap-2 mb-1">
                {entry.logo && <img src={entry.logo} alt="" style={{ height: 20 }} />}
                <span className="fw-semibold text-ink">{entry.name}</span>
                {method === entry.code && (
                  <i className="bi bi-check-circle-fill text-brand ms-auto" aria-hidden="true" />
                )}
              </div>

              {entry.payee?.account_number && (
                <div className="small text-soft">
                  {entry.payee.bank_name && <>{entry.payee.bank_name}<br /></>}
                  <span className="text-ink fw-semibold">{entry.payee.account_number}</span>
                  {entry.payee.account_name && <><br />{entry.payee.account_name}</>}
                </div>
              )}
            </button>
          </div>
        ))}
      </div>

      {chosen && ! chosen.is_gateway && (
        <div className="alert alert-light border small">
          {chosen.instructions && <p className="mb-2">{chosen.instructions}</p>}

          <div className="d-flex flex-wrap align-items-start gap-4">
            {chosen.payee?.account_number && (
              <dl className="row mb-0 flex-grow-1" style={{ maxWidth: 380 }}>
                {chosen.payee.bank_name && (
                  <>
                    <dt className="col-5 fw-normal text-soft">Bank</dt>
                    <dd className="col-7 mb-1">{chosen.payee.bank_name}</dd>
                  </>
                )}
                <dt className="col-5 fw-normal text-soft">Account name</dt>
                <dd className="col-7 mb-1">{chosen.payee.account_name || '—'}</dd>
                <dt className="col-5 fw-normal text-soft">Account number</dt>
                <dd className="col-7 mb-0 fw-semibold">{chosen.payee.account_number}</dd>
              </dl>
            )}

            {chosen.payee?.qr && (
              <div className="text-center">
                <img src={chosen.payee.qr} alt={`${chosen.name} QR code`} style={{ maxWidth: 140 }} />
                <div className="text-soft" style={{ fontSize: '.75rem' }}>Scan to pay</div>
              </div>
            )}
          </div>
        </div>
      )}

      {chosen?.is_gateway && (
        <div className="panel bg-surface">
          {chosen.instructions && <p className="small text-soft mb-3">{chosen.instructions}</p>}

          <button
            type="button"
            className="btn btn-brand btn-lg px-4"
            onClick={startGateway}
            disabled={starting}
          >
            {starting
              ? 'Opening ' + chosen.name + '…'
              : 'Pay ' + formatMoney(payment.amount_due_now, settings) + ' with ' + chosen.name}
          </button>

          {errors.method && <div className="text-danger small mt-2">{errors.method[0]}</div>}

          <p className="small text-soft mb-0 mt-3">
            You will be taken to {chosen.name} and brought straight back here. Nothing is taken
            until you confirm it there.
          </p>
        </div>
      )}

      {chosen && ! chosen.is_gateway && (
      <form onSubmit={submit} className="row g-3 mt-1">
        <div className="col-md-4">
          <label className="form-label" htmlFor="pay_amount">
            How much did you send? <span className="text-danger">*</span>
          </label>
          <input
            id="pay_amount"
            type="number"
            step="0.01"
            min="1"
            max={payment.balance_due}
            className={`form-control ${errors.amount ? 'is-invalid' : ''}`}
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            required
          />
          {errors.amount && <div className="invalid-feedback d-block">{errors.amount[0]}</div>}
        </div>

        <div className="col-md-4">
          <label className="form-label" htmlFor="pay_reference">Transaction reference</label>
          <input
            id="pay_reference"
            type="text"
            className={`form-control ${errors.transaction_reference ? 'is-invalid' : ''}`}
            value={reference}
            onChange={(event) => setReference(event.target.value)}
            placeholder="The id your app gave you"
          />
          {errors.transaction_reference && (
            <div className="invalid-feedback d-block">{errors.transaction_reference[0]}</div>
          )}
        </div>

        <div className="col-md-4">
          <label className="form-label" htmlFor="pay_proof">Receipt or screenshot</label>
          <input
            id="pay_proof"
            type="file"
            accept=".jpg,.jpeg,.png,.webp,.pdf"
            className={`form-control ${errors.proof ? 'is-invalid' : ''}`}
            onChange={(event) => setProof(event.target.files?.[0] || null)}
          />
          {errors.proof && <div className="invalid-feedback d-block">{errors.proof[0]}</div>}
        </div>

        <div className="col-12">
          <label className="form-label" htmlFor="pay_note">Anything we should know?</label>
          <textarea
            id="pay_note"
            rows={2}
            className="form-control"
            value={note}
            onChange={(event) => setNote(event.target.value)}
          />
        </div>

        <div className="col-12">
          <button type="submit" className="btn btn-brand px-4" disabled={saving || !method}>
            {saving ? 'Sending…' : 'I have paid'}
          </button>
        </div>
      </form>
      )}

      {history.length > 0 && (
        <div className="mt-4 pt-3 border-top">
          <h3 className="h6 mb-3">What you have sent</h3>
          <PaymentHistory history={history} settings={settings} />
        </div>
      )}
    </div>
  );
}

function PaymentHistory({ history, settings }) {
  return (
    <div className="table-responsive">
      <table className="table align-middle mb-0">
        <thead>
          <tr className="small text-soft">
            <th>Reference</th>
            <th>Amount</th>
            <th>Method</th>
            <th>Sent</th>
            <th>State</th>
          </tr>
        </thead>
        <tbody>
          {history.map((entry) => (
            <tr key={entry.reference}>
              <td className="small text-soft">{entry.transaction_reference || entry.reference}</td>
              <td className={entry.type === 'refund' ? 'text-danger' : ''}>
                {entry.type === 'refund' && '−'}{formatMoney(entry.amount, settings)}
              </td>
              <td className="small">{entry.method_label}</td>
              <td className="small text-soft">{entry.paid_at ? formatDateTime(entry.paid_at) : '—'}</td>
              <td>
                <span className={`status-pill ${STATUS_STYLE[entry.status] || 'text-bg-secondary'}`}>
                  {entry.status_label}
                </span>
                {entry.status === 'rejected' && entry.note && (
                  <div className="small text-danger mt-1">{entry.note}</div>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
