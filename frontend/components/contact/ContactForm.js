'use client';

import { useState } from 'react';
import toast from 'react-hot-toast';
import { apiFetch } from '@/lib/api';

const BLANK = { name: '', email: '', phone: '', subject: '', message: '' };

export default function ContactForm() {
  const [form, setForm] = useState(BLANK);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const update = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setErrors({});

    try {
      const response = await apiFetch('/contact', { method: 'POST', body: form });
      toast.success(response.message);
      setForm(BLANK);
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.message || 'Could not send your message.');
    } finally {
      setBusy(false);
    }
  };

  const field = (key, label, { type = 'text', colClass = 'col-md-6', required = false } = {}) => (
    <div className={colClass} key={key}>
      <label className="form-label" htmlFor={key}>
        {label} {required && <span className="text-danger">*</span>}
      </label>
      <input
        id={key}
        type={type}
        className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
        value={form[key]}
        onChange={update(key)}
        required={required}
      />
      {errors[key] && <div className="invalid-feedback">{errors[key][0]}</div>}
    </div>
  );

  return (
    <form onSubmit={submit} className="row g-3">
      {field('name', 'Your name', { required: true })}
      {field('phone', 'Phone', { type: 'tel' })}
      {field('email', 'Email', { type: 'email' })}
      {field('subject', 'Subject')}

      <div className="col-12">
        <label className="form-label" htmlFor="message">
          Message <span className="text-danger">*</span>
        </label>
        <textarea
          id="message"
          rows={5}
          className={`form-control ${errors.message ? 'is-invalid' : ''}`}
          value={form.message}
          onChange={update('message')}
          required
        />
        {errors.message && <div className="invalid-feedback">{errors.message[0]}</div>}
      </div>

      <div className="col-12">
        <button className="btn btn-brand px-4" type="submit" disabled={busy}>
          {busy ? 'Sending…' : 'Send message'}
        </button>
      </div>
    </form>
  );
}
