'use client';

import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { apiFetch } from '@/lib/api';

const BLANK = {
  label: 'Home',
  full_name: '',
  phone: '',
  address_line: '',
  area: '',
  city: '',
  postal_code: '',
  is_default: false,
};

export default function AddressesPage() {
  const { token, user } = useAuth();

  const [addresses, setAddresses] = useState(null);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(BLANK);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const load = () => {
    apiFetch('/addresses', { token })
      .then((response) => setAddresses(response.data || []))
      .catch(() => setAddresses([]));
  };

  useEffect(() => {
    if (token) load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  const openNew = () => {
    setForm({ ...BLANK, full_name: user?.name || '', phone: user?.phone || '' });
    setEditing('new');
    setErrors({});
  };

  const openEdit = (address) => {
    setForm({ ...BLANK, ...address });
    setEditing(address.id);
    setErrors({});
  };

  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});

    try {
      const isNew = editing === 'new';
      const response = await apiFetch(isNew ? '/addresses' : `/addresses/${editing}`, {
        method: isNew ? 'POST' : 'PUT',
        token,
        body: form,
      });

      toast.success(response.message);
      setEditing(null);
      load();
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.message || 'Could not save the address.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (address) => {
    if (!window.confirm('Delete this address?')) return;

    try {
      const response = await apiFetch(`/addresses/${address.id}`, { method: 'DELETE', token });
      toast.success(response.message);
      load();
    } catch {
      toast.error('Could not delete that address.');
    }
  };

  const field = (key, label, { type = 'text', colClass = 'col-md-6', required = false } = {}) => (
    <div className={colClass} key={key}>
      <label className="form-label" htmlFor={key}>{label}</label>
      <input
        id={key}
        type={type}
        className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
        value={form[key] || ''}
        onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.value }))}
        required={required}
      />
      {errors[key] && <div className="invalid-feedback">{errors[key][0]}</div>}
    </div>
  );

  if (addresses === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-4">
        <h1 className="h4 mb-0">Addresses</h1>
        {editing === null && (
          <button className="btn btn-brand btn-sm px-3" onClick={openNew}>
            <i className="bi bi-plus-lg me-1" />Add address
          </button>
        )}
      </div>

      {editing !== null && (
        <form className="panel mb-4" onSubmit={save}>
          <h2 className="h6 mb-3">{editing === 'new' ? 'New address' : 'Edit address'}</h2>

          <div className="row g-3">
            {field('label', 'Label')}
            {field('full_name', 'Full name', { required: true })}
            {field('phone', 'Phone', { type: 'tel', required: true })}
            {field('city', 'City / district', { required: true })}
            {field('address_line', 'Street address', { colClass: 'col-12', required: true })}
            {field('area', 'Area / thana')}
            {field('postal_code', 'Postal code')}

            <div className="col-12">
              <div className="form-check">
                <input
                  className="form-check-input"
                  type="checkbox"
                  id="is_default"
                  checked={Boolean(form.is_default)}
                  onChange={(event) => setForm((current) => ({ ...current, is_default: event.target.checked }))}
                />
                <label className="form-check-label" htmlFor="is_default">Use as my default address</label>
              </div>
            </div>
          </div>

          <div className="d-flex gap-2 mt-4">
            <button className="btn btn-brand px-4" type="submit" disabled={saving}>
              {saving ? 'Saving…' : 'Save address'}
            </button>
            <button className="btn btn-link text-body" type="button" onClick={() => setEditing(null)}>
              Cancel
            </button>
          </div>
        </form>
      )}

      {addresses.length === 0 && editing === null ? (
        <div className="panel">
          <div className="empty">
            <i className="bi bi-geo-alt" />
            <h2 className="h5">No saved addresses</h2>
            <p>Save one and checkout gets a lot faster.</p>
            <button className="btn btn-brand px-4" onClick={openNew}>Add your first address</button>
          </div>
        </div>
      ) : (
        <div className="row g-3">
          {addresses.map((address) => (
            <div className="col-md-6" key={address.id}>
              <div className="panel h-100">
                <div className="d-flex justify-content-between align-items-start mb-2">
                  <strong>{address.label}</strong>
                  {address.is_default && <span className="badge text-bg-light border">Default</span>}
                </div>

                <address className="small text-soft mb-3">
                  {address.full_name}<br />
                  {address.phone}<br />
                  {address.address_line}<br />
                  {address.area && <>{address.area}<br /></>}
                  {address.city} {address.postal_code}
                </address>

                <div className="d-flex gap-2">
                  <button className="btn btn-outline-secondary btn-sm" onClick={() => openEdit(address)}>Edit</button>
                  <button className="btn btn-link btn-sm text-danger" onClick={() => remove(address)}>Delete</button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
