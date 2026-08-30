'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch, toFormData } from '@/lib/api';
import ListingField from './ListingField';

// Enough to show an animal from every angle without a wall of photos. Matches
// the cap the API enforces.
const MAX_IMAGES = 8;

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
  min_weight_kg: '',
  max_weight_kg: '',
  weight_step_kg: 1,
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

  // Photos already on the listing, and ones picked but not yet uploaded.
  const [images, setImages] = useState(listing?.images || []);
  const [files, setFiles] = useState([]);

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

      // Photos need a listing to hang off, so on a brand new one they follow
      // the save rather than going up with it.
      if (files.length) {
        const id = listing?.id ?? response.data?.id;

        await apiFetch(`/seller/listings/${id}/images`, {
          method: 'POST',
          token,
          body: toFormData({ images: files }),
        });
      }

      toast.success(response.message);
      router.push('/seller/listings');
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.images?.[0] || error.message || 'Please check the form.');
    } finally {
      setSaving(false);
    }
  };

  const removeImage = async (image) => {
    try {
      const response = await apiFetch(`/seller/listings/${listing.id}/images/${image.id}`, {
        method: 'DELETE',
        token,
      });

      setImages(response.data.images || []);
      toast.success(response.message);
    } catch (error) {
      toast.error(error.message || 'Could not remove that photo.');
    }
  };

  const shared = { form, errors, onChange: set };

  // The rate the buyer will be charged at, worked out the same way the server
  // works it out — from the asking price against the advertised weight.
  const rate = (() => {
    const weight = Number(form.weight_kg);
    const asking = Number(form.price);
    const sale = Number(form.sale_price);
    const base = sale > 0 && sale < asking ? sale : asking;

    if (!(weight > 0) || !(base > 0)) return null;

    const topWeight = Number(form.max_weight_kg);
    const money = (value) => value.toFixed(2);

    return {
      perKg: money(base / weight),
      price: money(base),
      weight,
      topWeight,
      top: topWeight > weight ? money(base * topWeight / weight) : null,
    };
  })();

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
            label={`Asking price (${settings.currency_symbol || ''})`}
            hint="For the weight you entered above. Heavier animals are priced up from here." />
          <ListingField {...shared} name="sale_price" label="Discounted price" type="number"
            hint="Optional, and must be lower than the asking price." />

          <ListingField {...shared} name="min_weight_kg" label="Lightest you can supply (kg)"
            type="number"
            hint="Leave blank to start at the weight you listed above." />
          <ListingField {...shared} name="max_weight_kg" label="Heaviest you can supply (kg)"
            type="number"
            hint="Leave blank to sell this one animal at its own weight." />
          <ListingField {...shared} name="weight_step_kg" label="Steps of (kg)" type="number"
            hint="What the weight selector moves in — 1 kg is usual." />

          {/* Shown, never entered: a price against a weight is already a rate,
              and a third box would only be a third thing that can disagree. */}
          {rate && (
            <div className="col-12">
              <div className="alert alert-light border mb-0 py-2 small">
                <i className="bi bi-calculator me-1" aria-hidden="true" />
                <strong>{settings.currency_symbol || ''}{rate.perKg} / kg</strong>
                {' '}— worked out from {settings.currency_symbol || ''}{rate.price} at {rate.weight} kg.
                {rate.top && (
                  <> A buyer asking for {rate.topWeight} kg pays{' '}
                    <strong>{settings.currency_symbol || ''}{rate.top}</strong>.
                  </>
                )}
                {/* A weight in the name is fine when you sell one animal at one
                    weight. Across a range it contradicts every buyer who picks
                    anything else, and it travels with their order. */}
                {rate.top && /\d+\s*kg/i.test(form.name || '') && (
                  <div className="text-soft mt-1">
                    <i className="bi bi-info-circle me-1" aria-hidden="true" />
                    A weight at the end of your listing name is removed when you save. The
                    weight field above is what buyers see, and a name saying otherwise would
                    argue with their order.
                  </div>
                )}
              </div>
            </div>
          )}

          <ListingField {...shared} name="stock" label="How many available" type="number"
            hint={Number(form.max_weight_kg) > Number(form.weight_kg)
              || (form.min_weight_kg && Number(form.min_weight_kg) < Number(form.weight_kg))
              ? 'How many animals you can supply from this listing.'
              : 'Usually 1 — each goat is unique.'} />
        </div>
      </section>

      <section className="panel">
        <h2 className="h6 mb-1">Photos</h2>
        <p className="text-soft small mb-3">
          The first photo is the one buyers see in the shop. Up to {MAX_IMAGES}, 5MB each.
        </p>

        {images.length > 0 && (
          <div className="row row-cols-2 row-cols-sm-3 row-cols-md-4 g-2 mb-3">
            {images.map((image, index) => (
              <div className="col" key={image.id}>
                <div className="gallery__thumb position-relative" style={{ aspectRatio: '1' }}>
                  <img src={image.url} alt={image.alt || form.name} />

                  {index === 0 && (
                    <span className="badge text-bg-dark position-absolute top-0 start-0 m-1">
                      Main
                    </span>
                  )}

                  <button
                    type="button"
                    className="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 py-0 px-1"
                    aria-label={`Remove photo ${index + 1}`}
                    onClick={() => removeImage(image)}
                  >
                    <i className="bi bi-x-lg" aria-hidden="true" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}

        <input
          type="file"
          className={`form-control ${errors.images ? 'is-invalid' : ''}`}
          accept=".jpg,.jpeg,.png,.webp"
          multiple
          onChange={(event) => setFiles(Array.from(event.target.files || []))}
        />

        {errors.images && <div className="invalid-feedback d-block">{errors.images[0]}</div>}

        {files.length > 0 && (
          <p className="form-text mb-0">
            {files.length} {files.length === 1 ? 'photo' : 'photos'} will be uploaded when you save.
          </p>
        )}
      </section>

      <section className="panel">
        <h2 className="h6 mb-3">Description</h2>
        <div className="row g-3">
          {/* Two fields, two jobs: the summary is the lead line under the title
              on the goat page, the full description is the body below it. */}
          <ListingField {...shared} name="short_description" label="One-line summary" as="textarea" rows={2}
            colClass="col-12" hint="The lead line under the title, and the blurb on the shop card." />
          <ListingField {...shared} name="description" label="Full description" as="textarea" rows={6}
            colClass="col-12" hint="The main body on the goat's page — feed, temperament, history." />
          <ListingField {...shared} name="video_url" label="Video link" type="url" colClass="col-12"
            hint="Optional. Adds a “Watch video” button beside the photos." />
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
