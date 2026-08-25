'use client';

import { useState } from 'react';
import toast from 'react-hot-toast';
import { apiFetch } from '@/lib/api';

export default function InquiryForm({ slug }) {
  const [form, setForm] = useState({ name: '', phone: '', email: '', message: '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const update = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});

    try {
      const response = await apiFetch(`/goats/${slug}/inquiry`, { method: 'POST', body: form });
      toast.success(response.message);
      setForm({ name: '', phone: '', email: '', message: '' });
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.message || 'Could not send your enquiry.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="row g-3">
      <div className="col-md-6">
        <label className="form-label" htmlFor="inq-name">Your name</label>
        <input
          id="inq-name"
          className={`form-control ${errors.name ? 'is-invalid' : ''}`}
          value={form.name}
          onChange={update('name')}
          required
        />
        {errors.name && <div className="invalid-feedback">{errors.name[0]}</div>}
      </div>

      <div className="col-md-6">
        <label className="form-label" htmlFor="inq-phone">Phone</label>
        <input
          id="inq-phone"
          className={`form-control ${errors.phone ? 'is-invalid' : ''}`}
          value={form.phone}
          onChange={update('phone')}
          required
        />
        {errors.phone && <div className="invalid-feedback">{errors.phone[0]}</div>}
      </div>

      <div className="col-12">
        <label className="form-label" htmlFor="inq-message">What would you like to know?</label>
        <textarea
          id="inq-message"
          rows={3}
          className="form-control"
          value={form.message}
          onChange={update('message')}
          placeholder="Is this goat still available? Can I see more photos?"
        />
      </div>

      <div className="col-12">
        <button className="btn btn-brand px-4" type="submit" disabled={busy}>
          {busy ? 'Sending…' : 'Send enquiry'}
        </button>
      </div>
    </form>
  );
}
