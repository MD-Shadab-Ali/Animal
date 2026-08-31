'use client';

import { AnimatePresence, m } from 'motion/react';
import { useEffect, useRef } from 'react';
import { TRANSITION, dialogPanel } from '@/lib/motion';

/**
 * An in-page confirmation, instead of window.confirm().
 *
 * The native dialog cannot be styled, stamps "localhost:3000 says" across the
 * top, and blocks the whole tab. This is the same question asked in the site's
 * own voice.
 *
 * Bootstrap's CSS is loaded but not its JS, so the modal and its backdrop are
 * rendered directly rather than driven by the bootstrap bundle -- which also
 * meant the `fade` class this was wearing never faded anything, because there
 * was no script to take it off again. Motion drives it now, so the class is
 * gone and the transition is real.
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

  return (
    /*
     * mode="sync", not the "wait" a modal usually wants: the scrim and the
     * panel are two siblings that arrive and leave together, and "wait" would
     * make the second queue behind the first -- the backdrop would finish
     * fading out before the dialog had started.
     */
    <AnimatePresence mode="sync">
      {open && (
        <m.div
          key="confirm-backdrop"
          className="modal-backdrop"
          /*
           * Straight to .5 rather than to 1: without Bootstrap's `show` class
           * the backdrop is solid black, and .5 is the opacity that class
           * would have given it.
           */
          initial={{ opacity: 0 }}
          animate={{ opacity: 0.5 }}
          exit={{ opacity: 0 }}
          transition={TRANSITION.fast}
        />
      )}

      {open && (
        <m.div
          key="confirm-modal"
          className="modal d-block"
          role="dialog"
          aria-modal="true"
          aria-labelledby="confirm-dialog-title"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={TRANSITION.fast}
          // Only a click on the backdrop itself dismisses it, never a click that
          // started inside the panel and drifted out.
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !busy) onCancel?.();
          }}
        >
          <div className="modal-dialog modal-dialog-centered">
            {/*
              * The scale lives here rather than on the .modal above it. That
              * one is position: fixed, and a transform on an ancestor makes
              * that ancestor the containing block for it -- the same trap the
              * mobile drawer had to be moved out of the header to avoid. This
              * element is in normal flow, so it is free to move.
              */}
            <m.div
              className="modal-content"
              variants={dialogPanel}
              initial="hidden"
              animate="shown"
              exit="hidden"
              transition={TRANSITION.normal}
              style={{ borderRadius: 'var(--radius-lg, .75rem)' }}
            >
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
            </m.div>
          </div>
        </m.div>
      )}
    </AnimatePresence>
  );
}
