import Link from 'next/link';
import { Fragment } from 'react';
import { notFound } from 'next/navigation';
import { apiFetchOrNull } from '@/lib/api';
import { formatAge, formatDate, formatMoney } from '@/lib/format';
import { buildMetadata, getSiteData } from '@/lib/site';

/**
 * One animal, as reached by scanning its ear tag.
 *
 * Whoever is reading this is usually standing next to the goat -- most often a
 * buyer at the gate checking that the animal being handed over is the one they
 * paid for. So the page answers that question first and says little else.
 *
 * Never cached: an animal that has been sold has to read as sold the moment it
 * is, or the tag becomes a way of being told something untrue.
 */
async function getAnimal(token) {
  const response = await apiFetchOrNull(`/animals/${token}`, { cache: 'no-store' });

  return response?.data ?? null;
}

export async function generateMetadata({ params }) {
  const { token } = await params;
  const animal = await getAnimal(token);

  if (!animal) return buildMetadata({ title: 'Tag not found' });

  return buildMetadata({
    title: `${animal.tag || `${animal.weight_kg} kg`} — ${animal.listing.name}`,
    description: `${animal.weight_kg} kg ${animal.listing.name}.`,
  });
}

export default async function AnimalPage({ params }) {
  const { token } = await params;
  const [animal, site] = await Promise.all([getAnimal(token), getSiteData()]);

  if (!animal) notFound();

  const settings = site.settings;
  const sold = animal.status !== 'available';

  /*
   * This animal's own record. Every entry here was a column on the listing
   * until it became clear a listing is not an animal -- fifteen goats between
   * 20 and 60 kg cannot share one age or one tooth count, and dentition is
   * exactly how a goat's age is told.
   */
  const rows = [
    ['Weight', `${animal.weight_kg} kg`, 'fw-semibold'],
    ['Ear tag', animal.tag],
    ['Breed', animal.listing.breed],
    ['Age', formatAge(animal.age_months)],
    ['Teeth', animal.teeth_label],
    ['Colour', animal.color],
    ['Health', animal.health_status],
    ['Vaccination', animal.vaccination_label],
    ['Vet checked', formatDate(animal.vet_checked_at)],
    ['Dewormed', formatDate(animal.dewormed_at)],
    ['Price', formatMoney(animal.price, settings), 'fw-semibold text-brand'],
    ['State', animal.status_label],
  ].filter(([, value]) => value);

  // Named so an unrecorded reading reads as unrecorded rather than as absent.
  const missing = [
    ['age', animal.age_months],
    ['teeth', animal.teeth],
    ['vaccination', animal.is_vaccinated],
    ['vet check', animal.vet_checked_at],
  ].filter(([, value]) => value === null || value === undefined).map(([label]) => label);

  return (
    <div className="section">
      <div className="container" style={{ maxWidth: 560 }}>
        <div className="panel">
          <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
              <h1 className="h4 mb-1">{animal.listing.name}</h1>
              <p className="text-soft small mb-0">
                {animal.tag
                  ? <>Tag <strong className="text-ink">{animal.tag}</strong></>
                  : 'This animal'}
              </p>
            </div>

            <span className={`status-pill ${sold ? 'text-bg-secondary' : 'text-bg-success'}`}>
              {animal.status_label}
            </span>
          </div>

          {/*
            * This animal's own photograph, or nothing at all. Falling back to
            * the listing's gallery would put a different goat on a page whose
            * whole job is confirming which goat this is.
            */}
          {animal.image ? (
            <img
              src={animal.image}
              alt={`The ${animal.weight_kg} kg ${animal.listing.name}`}
              className="w-100 rounded mb-3"
              style={{ maxHeight: 320, objectFit: 'cover' }}
            />
          ) : (
            <p className="alert alert-light border small">
              No photograph has been taken of this particular animal yet.
            </p>
          )}

          <dl className="row mb-0">
            {rows.map(([label, value, strong]) => (
              <Fragment key={label}>
                <dt className="col-5 fw-normal text-soft">{label}</dt>
                <dd className={`col-7 mb-2 ${strong || ''}`}>{value}</dd>
              </Fragment>
            ))}
          </dl>

          {/*
            * Said plainly rather than left as a row of dashes. These readings
            * used to live on the listing, where one value spoke for every goat
            * behind it; on the animal itself an empty field means nobody has
            * taken the reading, and a buyer is better served knowing that than
            * being shown some other goat's figures.
            */}
          {missing.length > 0 && (
            <p className="small text-soft mt-3 mb-0">
              Not recorded for this animal yet: {missing.join(', ')}.
            </p>
          )}

          {animal.notes && <p className="small text-ink mt-3 mb-0">{animal.notes}</p>}
        </div>

        <p className="text-center small text-soft mt-3 mb-0">
          <Link href={`/goats/${animal.listing.slug}`} className="text-brand fw-semibold">
            See the {animal.listing.name} listing
          </Link>
        </p>
      </div>
    </div>
  );
}
