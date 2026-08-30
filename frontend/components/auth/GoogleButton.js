'use client';

import { useEffect, useRef, useState } from 'react';

const CLIENT_ID = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID;
const SCRIPT_SRC = 'https://accounts.google.com/gsi/client';

/**
 * "Continue with Google", the same on the sign-in and the sign-up page.
 *
 * There is deliberately no register-with-Google and sign-in-with-Google
 * distinction. This hands an ID token up to the caller and the API works out
 * which of the two it meant -- see AuthController::google().
 *
 * Google's own rendered button is used rather than one of ours. It carries
 * their branding rules with it, and people recognise it, which on a sign-in
 * form is worth more than matching our button radius exactly.
 */
export default function GoogleButton({ onCredential, disabled = false }) {
  const boxRef = useRef(null);
  const [failed, setFailed] = useState(false);

  // Read in the effect, never as a dependency: a new arrow every render would
  // tear the Google button down and rebuild it on every keystroke in the form.
  const credentialRef = useRef(onCredential);
  useEffect(() => { credentialRef.current = onCredential; });

  useEffect(() => {
    if (! CLIENT_ID) return undefined;

    let cancelled = false;

    const render = () => {
      const box = boxRef.current;
      if (cancelled || ! box || ! window.google?.accounts?.id) return;

      window.google.accounts.id.initialize({
        client_id: CLIENT_ID,
        ux_mode: 'popup',
        callback: (response) => credentialRef.current(response?.credential),

        // Fires when the popup is closed, blocked or refused. Without it those
        // simply do nothing and the person is left wondering.
        error_callback: (error) => {
          const reason = error?.type === 'popup_closed'
            ? null // They changed their mind. Saying so would be nagging.
            : 'Google sign-in could not be opened. Check that popups are allowed.';

          if (reason) credentialRef.current(null, reason);
        },
      });

      box.replaceChildren();

      window.google.accounts.id.renderButton(box, {
        type: 'standard',
        theme: 'outline',
        size: 'large',
        shape: 'pill',
        text: 'continue_with',
        logo_alignment: 'center',
        // Google wants a number of pixels, and caps it at 400. Measured rather
        // than guessed so it lines up with the form's own full-width buttons.
        width: Math.min(Math.round(box.offsetWidth) || 320, 400),
      });
    };

    const existing = document.querySelector(`script[src="${SCRIPT_SRC}"]`);

    if (existing) {
      // Already on the page from the other auth route. If it has finished
      // loading we can draw straight away; if not, wait for the same tag.
      if (window.google?.accounts?.id) render();
      else existing.addEventListener('load', render);

      return () => {
        cancelled = true;
        existing.removeEventListener('load', render);
      };
    }

    const script = document.createElement('script');
    script.src = SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    script.addEventListener('load', render);
    script.addEventListener('error', () => { if (! cancelled) setFailed(true); });
    document.head.appendChild(script);

    return () => { cancelled = true; };
  }, []);

  /*
   * No client id means Google sign-in is not configured for this environment.
   *
   * In front of a customer that has to render nothing: a button that cannot
   * work is worse than no button, and the password form beside it still works.
   *
   * While developing it has to render *something*, though. Silence here reads
   * as "the feature was never built" rather than "nobody has pasted the id in
   * yet" -- a question this component can answer for itself.
   */
  if (! CLIENT_ID) {
    if (process.env.NODE_ENV === 'production') return null;

    return (
      <div className="mb-3">
        <div className="auth-divider"><span>or</span></div>

        <button type="button" className="btn btn-outline-brand w-100" disabled>
          <i className="bi bi-google me-2" aria-hidden="true" />Continue with Google
        </button>

        <p className="form-text mb-0">
          Not configured yet. Set <code>NEXT_PUBLIC_GOOGLE_CLIENT_ID</code> in{' '}
          <code>frontend/.env.local</code> and <code>GOOGLE_CLIENT_ID</code> in{' '}
          <code>backend/.env</code>, then restart the dev server. This notice never
          reaches a production build.
        </p>
      </div>
    );
  }

  return (
    <div className="mb-3">
      <div className="auth-divider"><span>or</span></div>

      <div ref={boxRef} className={`google-button ${disabled ? 'is-disabled' : ''}`} />

      {failed && (
        <p className="form-text text-danger mb-0">
          Google sign-in could not be loaded. You can still sign in with your email and password.
        </p>
      )}
    </div>
  );
}
