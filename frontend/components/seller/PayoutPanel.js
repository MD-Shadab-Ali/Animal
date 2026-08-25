'use client';

import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { useSeller } from '@/context/SellerContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { formatMoney } from '@/lib/format';

/**
 * "Get paid" — where the seller sets the account their earnings go to and asks
 * for the balance to be sent.
 *
 * The API works out whether a request is allowed and why not, so this component
 * only renders that answer rather than second-guessing the money rules.
 */
export default function PayoutPanel({ payout, unpaid, onRequested }) {
  const { token } = useAuth();
  const { refresh } = useSeller();
  const settings = useSettings();

  const [methods, setMethods] = useState([]);
  const [editing, setEditing] = useState(!payout.has_details);
  const [form, setForm] = useState({
    payout_method: payout.method || '',
    payout_bank_name: payout.bank_name || '',
    payout_account_name: payout.account_name || '',
    payout_account_number: '',
  });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [requesting, setRequesting] = useState(false);

  useEffect(() => {
    if (!token || !payout.accepting) return;

    apiFetch('/seller/payout-methods', { token })
      .then((response) => setMethods(response.data || []))
      .catch(() => setMethods([]));
  }, [token, payout.accepting]);

  const set = (key) => (event) => {
    const { value } = event.target;
    setForm((current) => ({ ...current, [key]: value }));
    setErrors((current) => ({ ...current, [key]: undefined }));
  };

  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});

    try {
      const response = await apiFetch('/seller/payout-details', {
        method: 'PUT',
        token,
        body: form,
      });

      toast.success(response.message);
      setEditing(false);
      // The account number is never echoed back in full, so clear it rather
      // than leave a stale value sitting in the form.
      setForm((current) => ({ ...current, payout_account_number: '' }));
      await refresh();
      onRequested?.();
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.message || 'Could not save your payout details.');
    } finally {
      setSaving(false);
    }
  };

  const request = async () => {
    setRequesting(true);

    try {
      const response = await apiFetch('/seller/payouts', { method: 'POST', token });
      toast.success(response.message);
      onRequested?.();
    } catch (error) {
      toast.error(error.errors?.payout?.[0] || error.message || 'Could not request a payout.');
    } finally {
      setRequesting(false);
    }
  };

  // Nothing an admin has switched on can carry money to a seller yet.
  if (!payout.accepting) {
    return (
      <div className="panel">
        <h2 className="h6 mb-2">Get paid</h2>
        <p className="text-soft small mb-0">
          <i className="bi bi-hourglass-split me-1" aria-hidden="true" />
          Payouts are not open yet. We will let you know as soon as they are.
        </p>
      </div>
    );
  }

  const selected = methods.find((method) => method.code === form.payout_method);

  // Wallets identify an account by its number alone; a bank transfer needs the
  // bank as well, and the method itself says which it is.
  const wantsBankName = () => {
    if (!form.payout_method) return false;
    if (selected) return Boolean(selected.requires_bank_name);

    // The method list has not arrived yet, so fall back to what the server
    // already told us about the method currently on file.
    return form.payout_method === payout.method && Boolean(payout.needs_bank_name);
  };

  const needsBank = wantsBankName();

  // Four fields need a tighter column than three.
  const col = needsBank ? 'col-md-3' : 'col-md-4';

  return (
    <div className="panel">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h2 className="h6 mb-1">Get paid</h2>
          <p className="text-soft small mb-0">
            {payout.has_details
              ? 'Your earnings are sent to the account below once you request them.'
              : 'Tell us where to send your money, then request a payout whenever you are owed.'}
          </p>
        </div>

        {payout.has_details && !editing && (
          <button type="button" className="btn btn-quiet btn-sm" onClick={() => setEditing(true)}>
            <i className="bi bi-pencil me-1" aria-hidden="true" />Change
          </button>
        )}
      </div>

      {payout.has_details && !editing && (
        <div className="d-flex flex-wrap gap-4 mb-3">
          <div>
            <div className="small text-soft">Method</div>
            <div className="fw-semibold text-ink">{payout.method_label || payout.method}</div>
          </div>
          {payout.bank_name && (
            <div>
              <div className="small text-soft">Bank</div>
              <div className="fw-semibold text-ink">{payout.bank_name}</div>
            </div>
          )}
          <div>
            <div className="small text-soft">Account name</div>
            <div className="fw-semibold text-ink">{payout.account_name}</div>
          </div>
          <div>
            <div className="small text-soft">Account number</div>
            <div className="fw-semibold text-ink">{payout.account_hint}</div>
          </div>
        </div>
      )}

      {editing && (
        <form onSubmit={save} className="row g-3 mb-3">
          <div className={col}>
            <label className="form-label" htmlFor="payout_method">
              How should we pay you? <span className="text-danger">*</span>
            </label>
            <select
              id="payout_method"
              className={`form-select ${errors.payout_method ? 'is-invalid' : ''}`}
              value={form.payout_method}
              onChange={set('payout_method')}
              required
            >
              <option value="">Choose a method…</option>
              {methods.map((method) => (
                <option key={method.code} value={method.code}>{method.name}</option>
              ))}
            </select>
            {errors.payout_method && (
              <div className="invalid-feedback d-block">{errors.payout_method[0]}</div>
            )}
            {selected?.instructions && !errors.payout_method && (
              <div className="form-text">{selected.instructions}</div>
            )}
          </div>

          {needsBank && (
            <div className={col}>
              <label className="form-label" htmlFor="payout_bank_name">
                Bank name <span className="text-danger">*</span>
              </label>
              <input
                id="payout_bank_name"
                type="text"
                className={`form-control ${errors.payout_bank_name ? 'is-invalid' : ''}`}
                value={form.payout_bank_name}
                onChange={set('payout_bank_name')}
                placeholder="Nabil Bank"
                required
              />
              {errors.payout_bank_name && (
                <div className="invalid-feedback d-block">{errors.payout_bank_name[0]}</div>
              )}
            </div>
          )}

          <div className={col}>
            <label className="form-label" htmlFor="payout_account_name">
              Name on the account <span className="text-danger">*</span>
            </label>
            <input
              id="payout_account_name"
              type="text"
              className={`form-control ${errors.payout_account_name ? 'is-invalid' : ''}`}
              value={form.payout_account_name}
              onChange={set('payout_account_name')}
              required
            />
            {errors.payout_account_name && (
              <div className="invalid-feedback d-block">{errors.payout_account_name[0]}</div>
            )}
          </div>

          <div className={col}>
            <label className="form-label" htmlFor="payout_account_number">
              {needsBank ? 'Account number' : 'Account or wallet number'}{' '}
              <span className="text-danger">*</span>
            </label>
            <input
              id="payout_account_number"
              type="text"
              className={`form-control ${errors.payout_account_number ? 'is-invalid' : ''}`}
              value={form.payout_account_number}
              onChange={set('payout_account_number')}
              placeholder={payout.account_hint || ''}
              required
            />
            {errors.payout_account_number && (
              <div className="invalid-feedback d-block">{errors.payout_account_number[0]}</div>
            )}
          </div>

          <div className="col-12 d-flex gap-2">
            <button type="submit" className="btn btn-brand px-4" disabled={saving}>
              {saving ? 'Saving…' : 'Save payout details'}
            </button>

            {payout.has_details && (
              <button
                type="button"
                className="btn btn-quiet"
                onClick={() => {
                  setEditing(false);
                  setErrors({});
                }}
              >
                Cancel
              </button>
            )}
          </div>
        </form>
      )}

      <div className="d-flex flex-wrap align-items-center gap-3 pt-3 border-top">
        <button
          type="button"
          className="btn btn-brand px-4"
          onClick={request}
          disabled={!payout.can_request || requesting}
        >
          {requesting
            ? 'Requesting…'
            : `Request payout${unpaid > 0 ? ` of ${formatMoney(unpaid, settings)}` : ''}`}
        </button>

        {payout.blocked_reason && (
          <span className="small text-soft">
            <i className="bi bi-info-circle me-1" aria-hidden="true" />
            {payout.blocked_reason}
          </span>
        )}
      </div>
    </div>
  );
}
