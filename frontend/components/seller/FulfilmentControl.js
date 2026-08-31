'use client';

import { AnimatePresence, m } from 'motion/react';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { apiFetch } from '@/lib/api';
import { TRANSITION, disclosure } from '@/lib/motion';

const BADGE = {
  pending: 'text-bg-secondary',
  preparing: 'text-bg-warning',
  ready: 'text-bg-info',
  handed_over: 'text-bg-success',
  cancelled: 'text-bg-danger',
};

/**
 * Lets a seller move one of their own sales forward. Scoped to a single line
 * because an order can hold goats from more than one seller.
 */
export default function FulfilmentControl({ item, onUpdated }) {
  const { token } = useAuth();
  const [busy, setBusy] = useState(null);
  const [note, setNote] = useState('');
  const [showNote, setShowNote] = useState(false);

  const fulfilment = item.fulfilment || {};
  const next = fulfilment.next || [];

  const advance = async (status) => {
    setBusy(status);

    try {
      const response = await apiFetch(`/seller/order-items/${item.id}/status`, {
        method: 'PUT',
        token,
        body: { status, note: note.trim() || undefined },
      });

      toast.success(response.message);
      setNote('');
      setShowNote(false);
      onUpdated?.();
    } catch (error) {
      toast.error(error.errors?.status?.[0] || error.errors?.item?.[0] || error.message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="mt-2 pt-2 border-top">
      <div className="d-flex flex-wrap align-items-center gap-2">
        <span className="small text-soft">Your progress:</span>

        <span className={`status-pill ${BADGE[fulfilment.status] || 'text-bg-secondary'}`}>
          {fulfilment.label}
        </span>

        {fulfilment.note && (
          <span className="small text-soft fst-italic">“{fulfilment.note}”</span>
        )}
      </div>

      {next.length > 0 && (
        <>
          <div className="d-flex flex-wrap align-items-center gap-2 mt-2">
            <span className="small text-soft">Mark as:</span>

            {next.map((step) => (
              <button
                key={step.value}
                type="button"
                className={`btn btn-sm ${step.value === next[0].value ? 'btn-brand' : 'btn-quiet'}`}
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

          {/* Opens by growing rather than by appearing, so the rows of the
              order list below do not jump a notch each time it is toggled. */}
          <AnimatePresence initial={false}>
            {showNote && (
              <m.div
                key="note"
                variants={disclosure}
                initial="hidden"
                animate="shown"
                exit="hidden"
                transition={TRANSITION.fast}
                style={{ overflow: 'hidden' }}
              >
                <div className="mt-2">
                  <label className="visually-hidden" htmlFor={`note-${item.id}`}>Note for our team</label>
                  <input
                    id={`note-${item.id}`}
                    className="form-control form-control-sm"
                    placeholder="Anything our collection team should know?"
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                    maxLength={500}
                  />
                  <div className="form-text">Sent to us with your next update.</div>
                </div>
              </m.div>
            )}
          </AnimatePresence>
        </>
      )}
    </div>
  );
}
