'use client';

import { useEffect, useState } from 'react';

// Roughly half a screen down: far enough that the header is long gone and the
// button is answering a real question, near enough that it is there when the
// reader first wants it.
const SHOW_AFTER = 400;

/**
 * Back-to-top button, pinned bottom-right.
 *
 * It stays mounted and hides with visibility rather than unmounting, so it can
 * fade out instead of blinking away -- and because a visibility-hidden button
 * drops out of the tab order on its own, which a button faded to opacity 0
 * does not.
 */
export default function ScrollToTop() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    let frame = null;

    const onScroll = () => {
      // Scroll fires far faster than the page can paint, so the read is
      // coalesced onto the next frame rather than run on every event.
      if (frame !== null) return;
      frame = requestAnimationFrame(() => {
        frame = null;
        setVisible(window.scrollY > SHOW_AFTER);
      });
    };

    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    return () => {
      window.removeEventListener('scroll', onScroll);
      if (frame !== null) cancelAnimationFrame(frame);
    };
  }, []);

  const toTop = () => {
    const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: calm ? 'auto' : 'smooth' });
  };

  return (
    <button
      type="button"
      className={`to-top ${visible ? 'is-visible' : ''}`}
      onClick={toTop}
      aria-label="Back to top"
      title="Back to top"
    >
      <i className="bi bi-arrow-up" aria-hidden="true" />
    </button>
  );
}
