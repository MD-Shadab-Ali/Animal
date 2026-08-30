'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import toast from 'react-hot-toast';
import GoogleButton from '@/components/auth/GoogleButton';
import RecaptchaCheckbox, { RECAPTCHA_ENABLED } from '@/components/auth/RecaptchaCheckbox';
import { useAuth } from '@/context/AuthContext';

export default function RegisterPage() {
  const router = useRouter();
  const { register, verifyEmail, resendVerification, loginWithGoogle } = useAuth();

  // Signing up is now two steps: details, then the code that proves the
  // address. `pending` holds the address between them.
  const [pending, setPending] = useState(null);
  const [code, setCode] = useState('');

  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  // The robot check sits on every signup, and the submit button waits for it.
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
      const result = await register({ ...form, recaptcha_token: recaptchaToken });

      toast.success(`We sent a code to ${result.email}.`);
      setPending(result.email);
    } catch (error) {
      const fieldErrors = error.errors || {};
      setErrors(fieldErrors);

      // A token is spent once it has been submitted, whatever the outcome, so
      // the widget goes back to an unticked box rather than holding a dead one.
      setRecaptchaToken(null);
      setRecaptchaReset((count) => count + 1);

      toast.error(fieldErrors.recaptcha?.[0] || error.message || 'Could not create your account.');
    } finally {
      setBusy(false);
    }
  };

  const confirm = async (event) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});

    try {
      const user = await verifyEmail(pending, code);
      toast.success(`Welcome, ${user.name.split(' ')[0]}.`);

      // Same rule as sign-in: start on the home page, never on whatever page
      // the previous person was looking at.
      router.replace('/');
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.code?.[0] || error.message || 'Could not verify that code.');
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    try {
      const response = await resendVerification(pending);
      toast.success(response.message);
    } catch (error) {
      toast.error(error.errors?.email?.[0] || 'Could not send another code.');
    }
  };

  /*
   * The same handler, and the same button, as the sign-in page. Google has
   * already proved the address, so there is no code to email and no second
   * step -- this lands straight on a signed-in home page.
   */
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

  const field = (key, label, type = 'text', autoComplete) => (
    <div className="mb-3" key={key}>
      <label className="form-label" htmlFor={key}>{label}</label>
      <input
        id={key}
        type={type}
        className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
        value={form[key]}
        onChange={update(key)}
        autoComplete={autoComplete}
        required
      />
      {errors[key] && <div className="invalid-feedback">{errors[key][0]}</div>}
    </div>
  );

  // The code step replaces the form rather than sitting beside it: the details
  // are already accepted, and showing them again invites editing an address
  // the code was already sent to.
  if (pending) {
    return (
      <div className="section">
        <div className="container" style={{ maxWidth: 460 }}>
          <form onSubmit={confirm} className="panel">
            <h1 className="h4 mb-1">Check your email</h1>
            <p className="text-soft small mb-4">
              We sent a 6-digit code to <strong>{pending}</strong>. Enter it to finish
              creating your account.
            </p>

            <div className="mb-4">
              <label className="form-label" htmlFor="code">Verification code</label>
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

            <button className="btn btn-brand w-100 mb-3" type="submit" disabled={busy || code.length < 6}>
              {busy ? 'Checking…' : 'Verify and continue'}
            </button>

            <p className="text-center small mb-0">
              Nothing arrived?{' '}
              <button type="button" className="btn btn-link p-0 align-baseline text-brand" onClick={resend}>
                Send another code
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
          <h1 className="h4 mb-1">Create your account</h1>
          <p className="text-soft small mb-4">Track orders, save addresses and keep a wishlist.</p>

          {field('name', 'Full name', 'text', 'name')}
          {field('email', 'Email', 'email', 'email')}
          {field('phone', 'Phone number', 'tel', 'tel')}
          {field('password', 'Password', 'password', 'new-password')}
          {field('password_confirmation', 'Confirm password', 'password', 'new-password')}

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
            {busy ? 'Creating account…' : 'Create account'}
          </button>

          <GoogleButton onCredential={continueWithGoogle} disabled={busy} />

          <p className="text-center small mb-0">
            Already registered? <Link href="/login" className="text-brand fw-semibold">Sign in</Link>
          </p>
        </form>
      </div>
    </div>
  );
}
