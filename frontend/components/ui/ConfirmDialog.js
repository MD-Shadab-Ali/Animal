'use client';

import { useEffect, useRef } from 'react';

/**
 * An in-page confirmation, instead of window.confirm().
 *
 * The native dialog cannot be styled, stamps "localhost:3000 says" across the
 * top, and blocks the whole tab. This is the same question asked in the site's
 * own voice.
 *
 * Bootstrap's CSS is loaded but not its JS, so the modal and its backdrop are
 * rendered directly rather than driven by the bootstrap bundle.
 */
export default function ConfirmDialog({
  open,
  title,
  lines = [],
  confirmLabel = 'Confirm',
  cancelLabel = 'Never mind',
  tone = 'danger',
  busy = false,
  onConfirm,
  onCancel,
}) {
  const confirmRef = useRef(null);

  // Escape closes it, and the page behind must not scroll away underneath.
  useEffect(() => {
    if (!open) return undefined;

    const onKeyDown = (event) => {
      if (event.key === 'Escape' && !busy) onCancel?.();
    };

    document.addEventListener('keydown', onKeyDown);
    document.body.classList.add('modal-open');

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.classList.remove('modal-open');
    };
  }, [open, busy, onCancel]);

  // Land on the confirm button so the keyboard works from the first keystroke.
  useEffect(() => {
    if (open) confirmRef.current?.focus();
  }, [open]);

  if (!open) return null;

  return (
    <>
      <div
        className="modal fade show d-block"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-dialog-title"
        // Only a click on the backdrop itself dismisses it, never a click that
        // started inside the panel and drifted out.
        onMouseDown={(event) => {
          if (event.target === event.currentTarget && !busy) onCancel?.();
        }}
      >
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content" style={{ borderRadius: 'var(--radius-lg, .75rem)' }}>
            <div className="modal-header border-0 pb-0">
              <h2 className="modal-title h6 mb-0" id="confirm-dialog-title">{title}</h2>
              <button
                type="button"
                className="btn-close"
                aria-label="Close"
                disabled={busy}
                onClick={() => onCancel?.()}
              />
            </div>

            <div className="modal-body">
              {lines.filter(Boolean).map((line, index) => (
                <p className={`mb-2 ${index === 0 ? '' : 'small text-soft'}`} key={line}>
                  {line}
                </p>
              ))}
            </div>

            <div className="modal-footer border-0 pt-0 gap-2">
              <button
                type="button"
                className="btn btn-quiet"
                onClick={() => onCancel?.()}
                disabled={busy}
              >
                {cancelLabel}
              </button>
              <button
                type="button"
                ref={confirmRef}
                className={`btn btn-${tone} px-4`}
                onClick={() => onConfirm?.()}
                disabled={busy}
              >
                {busy ? 'Working…' : confirmLabel}
              </button>
            </div>
          </div>
        </div>
      </div>

      <div className="modal-backdrop fade show" />
    </>
  );
}
