'use client';

import { useEffect, useRef, useState } from 'react';

export const SITE_KEY = process.env.NEXT_PUBLIC_RECAPTCHA_SITE_KEY;

/**
 * Whether the forms should hold their submit button back.
 *
 * With no site key there is no widget to tick, and gating on a token nobody can
 * produce would leave a dead form. The request goes instead, and the API says
 * plainly that the check is not configured.
 */
export const RECAPTCHA_ENABLED = Boolean(SITE_KEY);

const SCRIPT_SRC = 'https://www.google.com/recaptcha/api.js?onload=__ghRecaptchaReady&render=explicit';

/*
 * One load for the life of the tab, kept at module scope on purpose.
 *
 * Sign-in and sign-up are separate routes, and moving between them unmounts one
 * form and mounts the other. A promise held in the component would fetch
 * Google's script again on every such move; held here, the second form finds it
 * already resolved.
 */
let readyPromise = null;

function loadRecaptcha() {
  if (readyPromise) return readyPromise;

  readyPromise = new Promise((resolve, reject) => {
    if (window.grecaptcha?.render) {
      resolve(window.grecaptcha);
      return;
    }

    // The script calls this itself once its API is usable, which is what
    // render=explicit buys: no racing a global that appears mid-parse.
    window.__ghRecaptchaReady = () => resolve(window.grecaptcha);

    const script = document.createElement('script');
    script.src = SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    script.addEventListener('error', () => {
      readyPromise = null; // let a later mount try again
      reject(new Error('Could not load reCAPTCHA'));
    });

    document.head.appendChild(script);
  });

  return readyPromise;
}

/**
 * The "I'm not a robot" checkbox on the email and password forms.
 *
 * The token it produces is worth nothing until the API has checked it with
 * Google, which is where the real decision is made. This only collects it, and
 * tells the form when it has gone stale.
 */
export default function RecaptchaCheckbox({ onChange, error, resetToken = 0, disabled = false }) {
  const boxRef = useRef(null);
  const widgetRef = useRef(null);
  const [failed, setFailed] = useState(false);
  const [expired, setExpired] = useState(false);

  // Read in effects, never as a dependency: an inline arrow from the parent
  // would otherwise tear the widget down and rebuild it on every keystroke.
  const changeRef = useRef(onChange);
  useEffect(() => { changeRef.current = onChange; });

  useEffect(() => {
    if (! SITE_KEY) return undefined;

    let cancelled = false;

    loadRecaptcha()
      .then((grecaptcha) => {
        // widgetRef guards against a second render in React StrictMode, which
        // would otherwise draw two checkboxes.
        if (cancelled || ! boxRef.current || widgetRef.current !== null) return;

        widgetRef.current = grecaptcha.render(boxRef.current, {
          sitekey: SITE_KEY,
          callback: (token) => {
            setExpired(false);
            changeRef.current(token);
          },
          // A token is good for about two minutes. Google tells us when it has
          // lapsed, which is better than the form finding out on submit.
          'expired-callback': () => {
            setExpired(true);
            changeRef.current(null);
          },
          'error-callback': () => {
            setFailed(true);
            changeRef.current(null);
          },
        });
      })
      .catch(() => {
        if (! cancelled) setFailed(true);
      });

    return () => { cancelled = true; };
  }, []);

  /*
   * A rejected submit spends the token, so the form asks for a fresh tick by
   * bumping resetToken. Skipped on the first render, when there is nothing to
   * reset yet.
   */
  useEffect(() => {
    if (resetToken === 0 || widgetRef.current === null || ! window.grecaptcha) return;

    window.grecaptcha.reset(widgetRef.current);
    setExpired(false);
    changeRef.current(null);
  }, [resetToken]);

  if (! SITE_KEY) {
    if (process.env.NODE_ENV === 'production') return null;

    return (
      <p className="form-text text-danger mb-3">
        Robot check not configured. Set <code>NEXT_PUBLIC_RECAPTCHA_SITE_KEY</code> in{' '}
        <code>frontend/.env.local</code> and <code>RECAPTCHA_SECRET_KEY</code> in{' '}
        <code>backend/.env</code>, then restart the dev server.
      </p>
    );
  }

  return (
    <div className="mb-3">
      <div ref={boxRef} className={`recaptcha ${disabled ? 'is-disabled' : ''}`} />

      <div aria-live="polite">
        {expired && ! error && (
          <p className="form-text text-warning mb-0">
            That check expired. Please tick the box again.
          </p>
        )}

        {error && <p className="form-text text-danger mb-0">{error}</p>}

        {failed && (
          <p className="form-text text-danger mb-0">
            Could not load the robot check. Check your connection and reload the page.
          </p>
        )}
      </div>
    </div>
  );
}
