'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';

export default function LoginPage() {
  const router = useRouter();
  const { login } = useAuth();

  const [form, setForm] = useState({ email: '', password: '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const update = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});

    try {
      const user = await login(form);
      toast.success(`Welcome back, ${user.name.split(' ')[0]}.`);

      // Always land on the home page. Carrying a "redirect" through sign-in
      // would drop a new person onto wherever the previous user happened to be.
      router.replace('/');
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.email?.[0] || error.message || 'Could not sign you in.');
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

          <div className="mb-4" />

          <button className="btn btn-brand w-100 mb-3" type="submit" disabled={busy}>
            {busy ? 'Signing in…' : 'Sign in'}
          </button>

          <p className="text-center small mb-0">
            New here? <Link href="/register" className="text-brand fw-semibold">Create an account</Link>
          </p>
        </form>
      </div>
    </div>
  );
}
