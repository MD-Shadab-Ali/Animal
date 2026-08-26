'use client';

import { useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';

/**
 * Getting your money back on a cancelled order.
 *
 * Where the money goes is asked for rather than assumed: a wallet payment may
 * need returning to a bank, and only the buyer knows.
 */
export default function OrderRefund({ order, onRequested }) {
  const { token } = useAuth();
  const settings = useSettings();

  const refund = order.refund || {};
  // The rails we can send money out on, served with the order itself.
  const methods = refund.methods || [];

  const [form, setForm] = useState({
    method: '',
    refund_to_name: '',
    refund_to_account: '',
    refund_to_bank: '',
    refund_reason: '',
  });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const set = (key) => (event) => {
    const { value } = event.target;
    setForm((current) => ({ ...current, [key]: value }));
    setErrors((current) => ({ ...current, [key]: undefined }));
  };

  // Derived, not stored: the method the order was paid on is not always one we
  // can send money back out on — cash on delivery being the obvious case — and
  // a <select> holding a value with no matching <option> just renders blank.
  const chosen = methods.find((entry) => entry.code === form.method)
    ?? methods.find((entry) => entry.code === order.payment_method)
    ?? methods[0];

  // A bank account number means nothing without the bank, same as a payout.
  const needsBank = Boolean(chosen?.needs_bank_name);

  // Three fields across, or four once a bank joins them.
  const col = needsBank ? 'col-md-3' : 'col-md-4';

  const submit = async (event) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});

    try {
      const response = await apiFetch(`/orders/${order.order_number}/refunds`, {
        method: 'POST',
        token,
        body: { ...form, method: chosen?.code || '' },
      });

      toast.success(response.message);
      onRequested?.();
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.refund?.[0] || error.message || 'Could not request your refund.');
    } finally {
      setSaving(false);
    }
  };

  // Nothing was ever paid, so there is nothing to give back.
  if (!refund.amount && !refund.requested && !refund.sent) return null;

  if (refund.sent > 0 && !refund.can_request && !refund.requested) {
    return (
      <div className="panel">
        <h2 className="h6 mb-2">
          <i className="bi bi-check-circle-fill me-1 text-success" aria-hidden="true" />
          Refunded
        </h2>
        <p className="text-soft small mb-1">
          We sent {formatMoney(refund.sent, settings)} back
          {refund.destination ? ` to ${refund.destination}` : ''}
          {refund.sent_at ? ` on ${formatDateTime(refund.sent_at)}` : ''}.
        </p>

        {/* What this rail actually does. A wallet lands instantly — telling
            someone to wait two days for money already in their hand is how you
            earn a support call. With no ETA on file, promise nothing. */}
        <p className="text-soft small mb-0">
          {refund.eta
            ? `Refunds by ${refund.method_label} usually arrive ${refund.eta}.`
            : 'Please allow a little time for it to show on your side.'}
          {refund.reference && (
            <>
              {' '}Reference <span className="text-ink">{refund.reference}</span> — quote this
              if you need to chase it.
            </>
          )}
        </p>
      </div>
    );
  }

  if (refund.requested) {
    return (
      <div className="panel">
        <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
          <h2 className="h6 mb-0">
            <i className="bi bi-hourglass-split me-1 text-warning" aria-hidden="true" />
            Refund on its way
          </h2>
          <span className="fw-bold text-brand">{formatMoney(refund.amount, settings)}</span>
        </div>
        <p className="text-soft small mb-0">
          We have your request from {formatDateTime(refund.requested_at)} and are sending
          {refund.destination ? ` it to ${refund.destination}` : ' your money back'}. You will
          get an email the moment it leaves our account
          {refund.eta ? `, and it should arrive ${refund.eta} after that` : ''}.
        </p>
      </div>
    );
  }

  // No admin has opened a rail we can send money out on, so there is nothing
  // to choose from — say that rather than showing an empty dropdown.
  if (!methods.length) {
    return (
      <div className="panel">
        <h2 className="h6 mb-2">Your refund</h2>
        <p className="text-soft small mb-0">
          <i className="bi bi-telephone me-1" aria-hidden="true" />
          You paid {formatMoney(refund.amount, settings)} before this order was cancelled. We
          will call you to arrange sending it back.
        </p>
      </div>
    );
  }

  return (
    <div className="panel">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
        <h2 className="h6 mb-0">Ask for your money back</h2>
        <span className="fw-bold text-brand">{formatMoney(refund.amount, settings)} to refund</span>
      </div>
      <p className="text-soft small mb-4">
        You paid {formatMoney(refund.amount, settings)} before this order was cancelled. Tell us
        where to send it and we will return it.
      </p>

      <form onSubmit={submit} className="row g-3">
        <div className={col}>
          <label className="form-label" htmlFor="refund_method">
            Send it back by <span className="text-danger">*</span>
          </label>
          <select
            id="refund_method"
            className={`form-select ${errors.method ? 'is-invalid' : ''}`}
            value={chosen?.code || ''}
            onChange={set('method')}
            required
          >
            {methods.map((method) => (
              <option key={method.code} value={method.code}>{method.name}</option>
            ))}
          </select>
          {errors.method && <div className="invalid-feedback d-block">{errors.method[0]}</div>}
        </div>

        {needsBank && (
          <div className={col}>
            <label className="form-label" htmlFor="refund_to_bank">
              Bank <span className="text-danger">*</span>
            </label>
            <input
              id="refund_to_bank"
              type="text"
              className={`form-control ${errors.refund_to_bank ? 'is-invalid' : ''}`}
              value={form.refund_to_bank}
              onChange={set('refund_to_bank')}
              placeholder="Nabil Bank"
              required
            />
            {errors.refund_to_bank && (
              <div className="invalid-feedback d-block">{errors.refund_to_bank[0]}</div>
            )}
          </div>
        )}

        <div className={col}>
          <label className="form-label" htmlFor="refund_to_name">
            Name on the account <span className="text-danger">*</span>
          </label>
          <input
            id="refund_to_name"
            type="text"
            className={`form-control ${errors.refund_to_name ? 'is-invalid' : ''}`}
            value={form.refund_to_name}
            onChange={set('refund_to_name')}
            required
          />
          {errors.refund_to_name && (
            <div className="invalid-feedback d-block">{errors.refund_to_name[0]}</div>
          )}
        </div>

        <div className={col}>
          <label className="form-label" htmlFor="refund_to_account">
            Account or wallet number <span className="text-danger">*</span>
          </label>
          <input
            id="refund_to_account"
            type="text"
            className={`form-control ${errors.refund_to_account ? 'is-invalid' : ''}`}
            value={form.refund_to_account}
            onChange={set('refund_to_account')}
            required
          />
          {errors.refund_to_account && (
            <div className="invalid-feedback d-block">{errors.refund_to_account[0]}</div>
          )}
        </div>

        <div className="col-12">
          <label className="form-label" htmlFor="refund_reason">Why did you cancel?</label>
          <textarea
            id="refund_reason"
            rows={2}
            className="form-control"
            value={form.refund_reason}
            onChange={set('refund_reason')}
            placeholder="Optional, but it helps us do better"
          />
        </div>

        <div className="col-12">
          <button type="submit" className="btn btn-brand px-4" disabled={saving}>
            {saving ? 'Sending…' : `Request ${formatMoney(refund.amount, settings)} back`}
          </button>
        </div>
      </form>
    </div>
  );
}
