'use client';

import { useState } from 'react';
import toast from 'react-hot-toast';
import { apiFetch } from '@/lib/api';

export default function NewsletterForm() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (event) => {
    event.preventDefault();
    if (!email.trim()) return;

    setBusy(true);
    try {
      const response = await apiFetch('/subscribe', { method: 'POST', body: { email } });
      toast.success(response.message);
      setEmail('');
    } catch (error) {
      toast.error(error.errors?.email?.[0] || 'Could not subscribe.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit}>
      <label htmlFor="newsletter-email" className="form-label text-white-50 mb-2">New goats, straight to your inbox</label>
      <div className="input-group">
        <input
          id="newsletter-email"
          type="email"
          className="form-control"
          placeholder="you@example.com"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          required
        />
        <button className="btn btn-cta" type="submit" disabled={busy}>
          {busy ? <span className="spinner-border spinner-border-sm" /> : <i className="bi bi-arrow-right" />}
        </button>
      </div>
    </form>
  );
}
