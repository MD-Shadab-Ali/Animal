'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';

export default function RegisterPage() {
  const router = useRouter();
  const { register } = useAuth();

  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const update = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});

    try {
      const user = await register(form);
      toast.success(`Welcome, ${user.name.split(' ')[0]}.`);

      // Same rule as sign-in: start on the home page, never on whatever page
      // the previous person was looking at.
      router.replace('/');
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.message || 'Could not create your account.');
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

          <button className="btn btn-brand w-100 mb-3" type="submit" disabled={busy}>
            {busy ? 'Creating account…' : 'Create account'}
          </button>

          <p className="text-center small mb-0">
            Already registered? <Link href="/login" className="text-brand fw-semibold">Sign in</Link>
          </p>
        </form>
      </div>
    </div>
  );
}
