'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { useSeller } from '@/context/SellerContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch, toFormData } from '@/lib/api';
import DocumentUpload from './DocumentUpload';

const BLANK = {
  farm_name: '',
  contact_phone: '',
  contact_email: '',
  city: '',
  area: '',
  postal_code: '',
  address_line: '',
  national_id: '',
  bio: '',
};

export default function SellerApplicationForm() {
  const router = useRouter();
  const settings = useSettings();
  const { token, user } = useAuth();
  const { setSeller } = useSeller();

  const [form, setForm] = useState({
    ...BLANK,
    contact_phone: user?.phone || '',
    contact_email: user?.email || '',
  });
  const [documents, setDocuments] = useState({ id_document: null, trade_licence: null });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const setDocument = (key) => (file) => {
    setDocuments((current) => ({ ...current, [key]: file }));
    setErrors((current) => ({ ...current, [key]: undefined }));
  };

  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const apply = async (event) => {
    event.preventDefault();

    // The ID is mandatory — stop here rather than making them wait on a round trip.
    if (!documents.id_document) {
      setErrors({ id_document: ['Please attach a photo or scan of your ID.'] });
      toast.error('An ID document is required.');
      document.getElementById('id_document')?.focus();
      return;
    }

    setSaving(true);
    setErrors({});

    try {
      const response = await apiFetch('/seller/apply', {
        method: 'POST',
        token,
        body: toFormData({ ...form, ...documents }),
      });

      setSeller(response.data);
      toast.success(response.message);
      router.push('/seller');
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.farm_name?.[0] || error.message || 'Could not send your application.');
    } finally {
      setSaving(false);
    }
  };

  const field = (key, label, { type = 'text', required = false, colClass = 'col-md-6', as = 'input', hint } = {}) => (
    <div className={colClass} key={key}>
      <label className="form-label" htmlFor={key}>
        {label} {required && <span className="text-danger">*</span>}
      </label>

      {as === 'textarea' ? (
        <textarea
          id={key}
          rows={3}
          className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
          value={form[key]}
          onChange={set(key)}
        />
      ) : (
        <input
          id={key}
          type={type}
          className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
          value={form[key]}
          onChange={set(key)}
          required={required}
        />
      )}

      {errors[key] && <div className="invalid-feedback d-block">{errors[key][0]}</div>}
      {hint && !errors[key] && <div className="form-text">{hint}</div>}
    </div>
  );

  return (
    <form onSubmit={apply} className="panel">
      <h2 className="h5 mb-1">Apply to sell</h2>
      <p className="text-soft small mb-4">We check every application by hand — usually within a day.</p>

      <div className="row g-3">
        {field('farm_name', 'Farm or business name', { required: true, colClass: 'col-12' })}
        {field('contact_phone', 'Phone number', { type: 'tel', required: true })}
        {field('contact_email', 'Email', { type: 'email' })}
        {field('city', 'City or district', { required: true, colClass: 'col-md-4' })}
        {field('area', 'Area or thana', { colClass: 'col-md-4' })}
        {field('postal_code', 'Postal code', { colClass: 'col-md-4' })}
        {field('address_line', 'Farm address', { colClass: 'col-12' })}
        {field('national_id', 'National ID number', { required: true, hint: 'The number printed on your NID.' })}
        {field('bio', 'About your farm', {
          as: 'textarea', colClass: 'col-12',
          hint: 'What you raise, and how long you have been farming.',
        })}
      </div>

      <hr className="my-4" />

      <h3 className="h6 mb-1">Proof of identity</h3>
      <p className="text-soft small mb-3">
        We check these by hand before approving anyone. They are never shown publicly.
      </p>

      <div className="row g-3">
        <DocumentUpload
          name="id_document"
          label="ID document"
          required
          value={documents.id_document}
          onChange={setDocument('id_document')}
          error={errors.id_document?.[0]}
          hint="A clear photo or scan of your NID, passport or driving licence. JPG, PNG, WEBP or PDF, up to 5MB."
        />

        <DocumentUpload
          name="trade_licence"
          label="Trade licence"
          value={documents.trade_licence}
          onChange={setDocument('trade_licence')}
          error={errors.trade_licence?.[0]}
          hint="Only if you have one — it is not required to sell."
        />
      </div>

      {settings.seller_terms && <p className="small text-soft mt-3 mb-0">{settings.seller_terms}</p>}

      <button className="btn btn-cta px-4 mt-4" type="submit" disabled={saving}>
        {saving ? 'Sending…' : 'Send application'}
      </button>
    </form>
  );
}
