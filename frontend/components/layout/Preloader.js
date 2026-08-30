'use client';

import { useEffect, useState } from 'react';

/**
 * The splash shown while the first page finishes loading.
 *
 * Rendered on the server as well as the client, so it is already in the HTML
 * the browser paints first. A client-only overlay would appear a frame *after*
 * the page it is meant to cover, which reads as a flash of the site followed by
 * a curtain -- the opposite of what a preloader is for.
 *
 * It covers the first load only. Client-side navigation keeps this component
 * mounted, so moving between pages never brings it back.
 */

// Kept in step with globals.scss: --preloader-fill and the overlay transition.
const FILL_MS = 1100;
const FADE_MS = 450;

// However badly the page is behaving, the site is reachable after this.
const SAFETY_MS = 6000;

export default function Preloader({ logo, siteName }) {
  const [done, setDone] = useState(false);
  const [gone, setGone] = useState(false);

  useEffect(() => {
    const timers = [];
    const started = performance.now();
    let dismissed = false;

    document.body.classList.add('has-preloader');

    const release = () => {
      document.body.classList.remove('has-preloader');
      setDone(true);
      timers.push(setTimeout(() => setGone(true), FADE_MS));
    };

    const dismiss = () => {
      if (dismissed) return;
      dismissed = true;

      // Let the bar actually reach the right-hand end first. Cutting it off
      // part-way is what makes a preloader look broken rather than quick.
      const remaining = Math.max(0, FILL_MS - (performance.now() - started));

      timers.push(setTimeout(release, remaining));
    };

    // On a warm cache the load event has often already fired by the time this
    // runs, and listening alone would wait for one that never comes.
    if (document.readyState === 'complete') {
      dismiss();
    } else {
      window.addEventListener('load', dismiss, { once: true });
    }

    // One stalled image should not hold the whole site behind the curtain.
    timers.push(setTimeout(dismiss, SAFETY_MS));

    return () => {
      window.removeEventListener('load', dismiss);
      timers.forEach(clearTimeout);
      document.body.classList.remove('has-preloader');
    };
  }, []);

  if (gone) return null;

  return (
    <div
      className={`preloader ${done ? 'is-done' : ''}`}
      role="status"
      aria-live="polite"
    >
      <div className="preloader__inner">
        {logo ? (
          // Decorative: the visually-hidden line below already names the site,
          // and alt text here would have a screen reader say it twice.
          <img className="preloader__logo" src={logo} alt="" />
        ) : (
          <span className="preloader__word">{siteName}</span>
        )}

        <span className="preloader__track" aria-hidden="true">
          <span className="preloader__bar" />
        </span>

        <span className="visually-hidden">Loading {siteName}</span>
      </div>
    </div>
  );
}
