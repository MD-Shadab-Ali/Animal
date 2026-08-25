'use client';

import { useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { apiFetch } from '@/lib/api';

/**
 * Order-level control, shown only for orders this seller supplied in full.
 * Anything with house stock or a second seller is run by the platform, and the
 * API refuses these calls for those.
 */
export default function OrderStatusControl({ order, onUpdated }) {
  const { token } = useAuth();
  const [busy, setBusy] = useState(null);
  const [note, setNote] = useState('');
  const [showNote, setShowNote] = useState(false);

  const next = order.next_status || [];

  const advance = async (status) => {
    setBusy(status);

    try {
      const response = await apiFetch(`/seller/orders/${order.order_number}/status`, {
        method: 'PUT',
        token,
        body: { status, note: note.trim() || undefined },
      });

      toast.success(response.message);
      setNote('');
      setShowNote(false);
      onUpdated?.();
    } catch (error) {
      toast.error(error.errors?.status?.[0] || error.errors?.order?.[0] || error.message);
    } finally {
      setBusy(null);
    }
  };

  if (order.status === 'cancelled') {
    return (
      <div className="alert alert-danger py-2 px-3 small mb-0 mt-3">
        <i className="bi bi-x-circle-fill me-1" aria-hidden="true" />
        This order was cancelled by our team. Nothing further is needed from you.
      </div>
    );
  }

  if (!next.length) {
    return (
      <div className="alert alert-success py-2 px-3 small mb-0 mt-3">
        <i className="bi bi-check-circle-fill me-1" aria-hidden="true" />
        Delivered. Your earnings will be settled in your next payout.
      </div>
    );
  }

  return (
    <div className="mt-3 pt-3 border-top">
      <div className="d-flex flex-wrap align-items-center gap-2">
        <span className="small fw-semibold text-ink">
          <i className="bi bi-person-check me-1" aria-hidden="true" />
          This order is yours to run
        </span>
        <span className="small text-soft">Move it to:</span>

        {next.map((step, index) => (
          <button
            key={step.value}
            type="button"
            className={`btn btn-sm ${index === 0 ? 'btn-brand' : 'btn-quiet'}`}
            onClick={() => advance(step.value)}
            disabled={busy !== null}
          >
            {busy === step.value
              ? <span className="spinner-border spinner-border-sm" aria-hidden="true" />
              : step.label}
          </button>
        ))}

        <button
          type="button"
          className="btn btn-link btn-sm text-decoration-none"
          onClick={() => setShowNote((value) => !value)}
        >
          {showNote ? 'Hide note' : 'Add a note'}
        </button>
      </div>

      {showNote && (
        <div className="mt-2">
          <label className="visually-hidden" htmlFor={`order-note-${order.order_number}`}>Note</label>
          <input
            id={`order-note-${order.order_number}`}
            className="form-control form-control-sm"
            placeholder="Anything our team should know?"
            value={note}
            onChange={(event) => setNote(event.target.value)}
            maxLength={500}
          />
        </div>
      )}

      <p className="small text-soft mb-0 mt-2">
        Need this order cancelled? <a href="/contact">Ask our team</a> — sellers cannot cancel a buyer&apos;s order.
      </p>
    </div>
  );
}
