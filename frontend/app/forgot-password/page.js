'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { apiFetch } from '@/lib/api';

/**
 * Asking for a reset link.
 *
 * The server answers the same way whether or not the address has an account,
 * so this page must too: saying "no such account" here would turn the form
 * into a way of finding out who has one.
 */
export default function ForgotPasswordPage() {
  const router = useRouter();

  const [email, setEmail] = useState('');
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);

  // The second step: the code that proves the address, and the password it
  // unlocks. Both are entered on the same screen so the code is used the
  // moment it is read, rather than sitting in an inbox while someone thinks.
  const [code, setCode] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});

    try {
      const response = await apiFetch('/auth/forgot-password', {
        method: 'POST',
        body: { email },
      });

      setSent(true);
      toast.success(response.message);
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.email?.[0] || error.message || 'Could not send the link.');
    } finally {
      setBusy(false);
    }
  };

  const reset = async (event) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});

    try {
      const response = await apiFetch('/auth/reset-password', {
        method: 'POST',
        body: { email, code, password, password_confirmation: confirmation },
      });

      toast.success(response.message);
      router.replace('/login');
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.code?.[0] || error.errors?.password?.[0]
        || error.message || 'Could not reset your password.');
    } finally {
      setBusy(false);
    }
  };

  if (sent) {
    return (
      <div className="section">
        <div className="container" style={{ maxWidth: 460 }}>
          <form onSubmit={reset} className="panel">
            <h1 className="h4 mb-1">Check your email</h1>
            <p className="text-soft small mb-4">
              If <strong>{email}</strong> belongs to an account, a 6-digit code is on its
              way. It expires in 10 minutes.
            </p>

            <div className="mb-3">
              <label className="form-label" htmlFor="code">Code</label>
              <input
                id="code"
                type="text"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                className={`form-control text-center ${errors.code ? 'is-invalid' : ''}`}
                style={{ letterSpacing: '0.4em', fontSize: '1.25rem' }}
                value={code}
                onChange={(event) => setCode(event.target.value.replace(/\D/g, ''))}
                required
              />
              {errors.code && <div className="invalid-feedback">{errors.code[0]}</div>}
            </div>

            <div className="mb-3">
              <label className="form-label" htmlFor="password">New password</label>
              <input
                id="password"
                type="password"
                className={`form-control ${errors.password ? 'is-invalid' : ''}`}
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                autoComplete="new-password"
                required
              />
              {errors.password
                ? <div className="invalid-feedback">{errors.password[0]}</div>
                : <div className="form-text">At least 8 characters.</div>}
            </div>

            <div className="mb-4">
              <label className="form-label" htmlFor="password_confirmation">Repeat it</label>
              <input
                id="password_confirmation"
                type="password"
                className="form-control"
                value={confirmation}
                onChange={(event) => setConfirmation(event.target.value)}
                autoComplete="new-password"
                required
              />
            </div>

            <button className="btn btn-brand w-100 mb-3" type="submit" disabled={busy || code.length < 6}>
              {busy ? 'Saving…' : 'Save new password'}
            </button>

            <p className="text-center small mb-0">
              <button
                type="button"
                className="btn btn-link p-0 align-baseline text-brand"
                onClick={() => setSent(false)}
              >
                Use a different email
              </button>
            </p>
          </form>
        </div>
      </div>
    );
  }

  return (
    <div className="section">
      <div className="container" style={{ maxWidth: 460 }}>
        <form onSubmit={submit} className="panel">
          <h1 className="h4 mb-1">Forgot your password?</h1>
          <p className="text-soft small mb-4">
            Tell us the email you signed up with and we will send you a code to choose a
            new password.
          </p>

          <div className="mb-4">
            <label className="form-label" htmlFor="email">Email</label>
            <input
              id="email"
              type="email"
              className={`form-control ${errors.email ? 'is-invalid' : ''}`}
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              autoComplete="email"
              required
            />
            {errors.email && <div className="invalid-feedback">{errors.email[0]}</div>}
          </div>

          <button className="btn btn-brand w-100 mb-3" type="submit" disabled={busy}>
            {busy ? 'Sending…' : 'Send me a code'}
          </button>

          <p className="text-center small mb-0">
            Remembered it? <Link href="/login" className="text-brand fw-semibold">Sign in</Link>
          </p>
        </form>
      </div>
    </div>
  );
}
