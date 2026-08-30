'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import toast from 'react-hot-toast';
import GoogleButton from '@/components/auth/GoogleButton';
import RecaptchaCheckbox, { RECAPTCHA_ENABLED } from '@/components/auth/RecaptchaCheckbox';
import { useAuth } from '@/context/AuthContext';

export default function LoginPage() {
  const router = useRouter();
  const { login, loginWithGoogle } = useAuth();

  const [form, setForm] = useState({ email: '', password: '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  // The robot check sits on every sign-in, and the submit button waits for it.
  const [recaptchaToken, setRecaptchaToken] = useState(null);
  const [recaptchaReset, setRecaptchaReset] = useState(0);

  const update = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const submit = async (event) => {
    event.preventDefault();

    // The button is disabled without a token, so this only catches a form
    // submitted by keyboard before the box was ticked.
    if (RECAPTCHA_ENABLED && ! recaptchaToken) {
      setErrors({ recaptcha: [`Please tick the "I'm not a robot" box.`] });
      return;
    }

    setBusy(true);
    setErrors({});

    try {
      const user = await login({ ...form, recaptcha_token: recaptchaToken });

      toast.success(`Welcome back, ${user.name.split(' ')[0]}.`);

      // Always land on the home page. Carrying a "redirect" through sign-in
      // would drop a new person onto wherever the previous user happened to be.
      router.replace('/');
    } catch (error) {
      const fieldErrors = error.errors || {};
      setErrors(fieldErrors);

      // A token is spent once it has been submitted, whatever the outcome, so
      // the widget goes back to an unticked box rather than holding a dead one.
      setRecaptchaToken(null);
      setRecaptchaReset((count) => count + 1);

      toast.error(fieldErrors.recaptcha?.[0] || fieldErrors.email?.[0] || error.message || 'Could not sign you in.');
    } finally {
      setBusy(false);
    }
  };

  const continueWithGoogle = async (credential, failure) => {
    if (! credential) {
      if (failure) toast.error(failure);
      return;
    }

    setBusy(true);

    try {
      const user = await loginWithGoogle(credential);
      toast.success(`Welcome, ${user.name.split(' ')[0]}.`);
      router.replace('/');
    } catch (error) {
      toast.error(error.errors?.credential?.[0] || error.message || 'Could not sign you in with Google.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="section">
      <div className="container" style={{ maxWidth: 460 }}>
        <form onSubmit={submit} className="panel">
          <h1 className="h4 mb-1">Sign in</h1>
          <p className="text-soft small mb-4">You need an account to place an order.</p>

          <div className="mb-3">
            <label className="form-label" htmlFor="email">Email</label>
            <input
              id="email"
              type="email"
              className={`form-control ${errors.email ? 'is-invalid' : ''}`}
              value={form.email}
              onChange={update('email')}
              autoComplete="email"
              required
            />
            {errors.email && <div className="invalid-feedback">{errors.email[0]}</div>}
          </div>

          <div className="mb-2">
            <label className="form-label" htmlFor="password">Password</label>
            <input
              id="password"
              type="password"
              className={`form-control ${errors.password ? 'is-invalid' : ''}`}
              value={form.password}
              onChange={update('password')}
              autoComplete="current-password"
              required
            />
            {errors.password && <div className="invalid-feedback">{errors.password[0]}</div>}
          </div>

          {/* Sits against the password field, which is the moment someone
              discovers they cannot remember it. */}
          <p className="text-end small mb-4">
            <Link href="/forgot-password" className="text-brand">Forgot your password?</Link>
          </p>

          <RecaptchaCheckbox
            onChange={setRecaptchaToken}
            error={errors.recaptcha?.[0]}
            resetToken={recaptchaReset}
            disabled={busy}
          />

          <button
            className="btn btn-brand w-100 mb-3"
            type="submit"
            disabled={busy || (RECAPTCHA_ENABLED && ! recaptchaToken)}
          >
            {busy ? 'Signing in…' : 'Sign in'}
          </button>

          <GoogleButton onCredential={continueWithGoogle} disabled={busy} />

          <p className="text-center small mb-0">
            New here? <Link href="/register" className="text-brand fw-semibold">Create an account</Link>
          </p>
        </form>
      </div>
    </div>
  );
}
