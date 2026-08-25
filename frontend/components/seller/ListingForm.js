'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import ListingField from './ListingField';

const BLANK = {
  category_id: '',
  name: '',
  breed: '',
  gender: 'male',
  age_months: '',
  weight_kg: '',
  color: '',
  teeth: '',
  health_status: '',
  is_vaccinated: false,
  price: '',
  sale_price: '',
  stock: 1,
  short_description: '',
  description: '',
  video_url: '',
};

export default function ListingForm({ listing = null }) {
  const router = useRouter();
  const { token } = useAuth();
  const settings = useSettings();

  const [categories, setCategories] = useState([]);
  // Seeded once from the prop — the parent only mounts this after loading.
  const [form, setForm] = useState(() => (listing
    ? {
      ...BLANK,
      ...Object.fromEntries(Object.keys(BLANK).map((key) => [key, listing[key] ?? BLANK[key]])),
      category_id: listing.category?.id ?? '',
    }
    : BLANK));
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    apiFetch('/categories')
      .then((response) => setCategories(response.data || []))
      .catch(() => setCategories([]));
  }, []);

  const set = (key) => (event) => {
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    setForm((current) => ({ ...current, [key]: value }));
  };

  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});

    // Empty optional numbers must be sent as null, not an empty string.
    const payload = Object.fromEntries(
      Object.entries(form).map(([key, value]) => [key, value === '' ? null : value])
    );

    try {
      const response = listing
        ? await apiFetch(`/seller/listings/${listing.id}`, { method: 'PUT', token, body: payload })
        : await apiFetch('/seller/listings', { method: 'POST', token, body: payload });

      toast.success(response.message);
      router.push('/seller/listings');
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.message || 'Please check the form.');
    } finally {
      setSaving(false);
    }
  };

  const shared = { form, errors, onChange: set };

  return (
    <form onSubmit={save} className="d-grid gap-4">
      <section className="panel">
        <h2 className="h6 mb-3">The animal</h2>
        <div className="row g-3">
          <ListingField {...shared} name="name" label="Listing title" required colClass="col-12"
            hint="For example: Black Bengal Buck — 22kg" />
          <ListingField {...shared} name="category_id" label="Category" required as="select"
            options={[['', 'Choose a category'], ...categories.map((c) => [c.id, c.name])]} />
          <ListingField {...shared} name="breed" label="Breed" />
          <ListingField {...shared} name="gender" label="Gender" required as="select"
            options={[['male', 'Male (buck)'], ['female', 'Female (doe)']]} />
          <ListingField {...shared} name="color" label="Colour" />
          <ListingField {...shared} name="age_months" label="Age in months" type="number" />
          <ListingField {...shared} name="weight_kg" label="Weight in kg" type="number" />
          <ListingField {...shared} name="teeth" label="Permanent teeth" type="number" hint="0, 2, 4, 6 or 8" />
          <ListingField {...shared} name="health_status" label="Health status"
            hint="For example: Vet checked — healthy" />

          <div className="col-12">
            <div className="form-check">
              <input
                className="form-check-input"
                type="checkbox"
                id="is_vaccinated"
                checked={Boolean(form.is_vaccinated)}
                onChange={set('is_vaccinated')}
              />
              <label className="form-check-label" htmlFor="is_vaccinated">This goat is vaccinated</label>
            </div>
          </div>
        </div>
      </section>

      <section className="panel">
        <h2 className="h6 mb-3">Price</h2>
        <div className="row g-3">
          <ListingField {...shared} name="price" type="number" required
            label={`Asking price (${settings.currency_symbol || ''})`} />
          <ListingField {...shared} name="sale_price" label="Discounted price" type="number"
            hint="Optional, and must be lower than the asking price." />
          <ListingField {...shared} name="stock" label="How many available" type="number"
            hint="Usually 1 — each goat is unique." />
        </div>
      </section>

      <section className="panel">
        <h2 className="h6 mb-3">Description</h2>
        <div className="row g-3">
          <ListingField {...shared} name="short_description" label="One-line summary" as="textarea" rows={2}
            colClass="col-12" hint="Shown on the card in the shop." />
          <ListingField {...shared} name="description" label="Full description" as="textarea" rows={6}
            colClass="col-12" hint="Feed, temperament, history — anything a buyer would ask." />
          <ListingField {...shared} name="video_url" label="Video link" type="url" colClass="col-12"
            hint="Optional video of the animal." />
        </div>
      </section>

      <div className="d-flex flex-wrap gap-2">
        <button className="btn btn-cta px-4" type="submit" disabled={saving}>
          {saving ? 'Saving…' : (listing ? 'Save changes' : 'Save as draft')}
        </button>
        <button className="btn btn-quiet" type="button" onClick={() => router.push('/seller/listings')}>
          Cancel
        </button>
      </div>

      <p className="small text-soft mb-0">
        Listings save as a draft first. Send it for review from the listings page when you are
        happy with it — our team checks every listing before it goes live.
      </p>
    </form>
  );
}
