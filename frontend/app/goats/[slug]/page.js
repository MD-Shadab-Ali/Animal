import Link from 'next/link';
import { notFound } from 'next/navigation';
import BuyBox from '@/components/goat/BuyBox';
import GoatGallery from '@/components/goat/GoatGallery';
import GoatGrid from '@/components/goat/GoatGrid';
import InquiryForm from '@/components/goat/InquiryForm';
import ReviewList from '@/components/goat/ReviewList';
import SectionHeader from '@/components/home/SectionHeader';
import { apiFetchOrNull } from '@/lib/api';
import { buildMetadata } from '@/lib/site';
import { formatAge } from '@/lib/format';

async function getGoat(slug) {
  const response = await apiFetchOrNull(`/goats/${slug}`, { revalidate: 30, tags: ['goats'] });
  return response?.data ?? null;
}

export async function generateMetadata({ params }) {
  const { slug } = await params;
  const goat = await getGoat(slug);

  if (!goat) return buildMetadata({ title: 'Goat not found' });

  return buildMetadata({
    title: goat.seo?.title || goat.name,
    description: goat.seo?.description,
    image: goat.thumbnail,
  });
}

export default async function GoatPage({ params }) {
  const { slug } = await params;
  const goat = await getGoat(slug);

  if (!goat) notFound();

  const related = (await apiFetchOrNull(`/goats/${slug}/related`, { revalidate: 60, tags: ['goats'] }))?.data || [];

  /*
   * A listing is not an animal.
   *
   * Behind this page are real goats -- fifteen of them, anywhere from 20 to
   * 60 kg -- and no single age, tooth count or colour is true of all of them.
   * The listing columns holding those values were never entered carelessly;
   * the fields simply had no correct answer to give. Where the farm has told
   * us which animals it actually holds, this page says what is true of the
   * group and leaves the individual readings to the animal's own page, which
   * is a tag scan away. Where it has not, the listing is still the best
   * description there is and nothing changes.
   */
  const pool = goat.pool || null;

  const ageRange = pool && pool.min_age_months !== null
    ? (pool.min_age_months === pool.max_age_months
      ? formatAge(pool.min_age_months)
      : `${formatAge(pool.min_age_months)} – ${formatAge(pool.max_age_months)}`)
    : null;

  const headline = [
    ['Breed', goat.breed],
    // The weight tile goes when there is a pool: the buyer chooses a weight
    // just below, and a fixed figure beside a slider offering a range only
    // ever read as a contradiction.
    ['Weight', pool ? null : (goat.weight_kg ? `${goat.weight_kg} kg` : null)],
    ['Age', pool ? ageRange : formatAge(goat.age_months)],
    ['Gender', goat.gender ? goat.gender[0].toUpperCase() + goat.gender.slice(1) : null],
    ['On the farm', pool ? `${pool.count} ${pool.count === 1 ? 'goat' : 'goats'}` : null],
  ].filter(([, value]) => value);

  const specs = [
    // Colour, dentition and a health reading belong to one goat. With a pool
    // behind this listing they are on each animal instead; the buyer sees the
    // ones for their own goat once it is set aside, or by scanning its tag.
    ['Colour', pool ? null : goat.color],
    ['Permanent teeth', !pool && goat.teeth != null ? String(goat.teeth) : null],
    ['Health', pool ? null : goat.health_status],
    // Kept either way: a vaccination round is done to a batch, so unlike the
    // rest of these it is a thing the whole listing can honestly claim.
    ['Vaccinated', goat.is_vaccinated ? 'Yes' : 'No'],
    ['SKU', goat.sku],
    ...(goat.specs || []).map((spec) => [spec.label, spec.value]),
  ].filter(([, value]) => value);

  return (
    <>
      <div className="pagehead py-3">
        <div className="container">
          <nav className="crumbs" aria-label="Breadcrumb">
            <Link href="/">Home</Link>
            <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
            <Link href="/shop">Shop</Link>
            {goat.category?.slug && (
              <>
                <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
                <Link href={`/shop?category=${goat.category.slug}`}>{goat.category.name}</Link>
              </>
            )}
            <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
            <span className="text-ink">{goat.name}</span>
          </nav>
        </div>
      </div>

      <div className="section pt-4">
        <div className="container">
          <div className="product-layout">
            <div className="product-layout__media">
              <GoatGallery goat={goat} />
            </div>

            <div className="product-layout__buy">
              <div className="d-flex flex-wrap gap-2 mb-3">
                {goat.category?.name && <span className="chip">{goat.category.name}</span>}
                {goat.is_vaccinated && (
                  <span className="badge-verified">
                    <i className="bi bi-patch-check-fill" aria-hidden="true" /> Vet checked
                  </span>
                )}
              </div>

              <h1 className="section-title mb-2">{goat.name}</h1>

              {goat.short_description && <p className="section-sub mb-4">{goat.short_description}</p>}

              {headline.length > 0 && (
                <dl className="spec-grid mb-4">
                  {headline.map(([label, value]) => (
                    <div className="spec-cell" key={label}>
                      <dt>{label}</dt>
                      <dd>{value}</dd>
                    </div>
                  ))}
                </dl>
              )}

              {goat.sold_by && (
                <div className="panel mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div className="d-flex align-items-center gap-2">
                    <span className="avatar" style={{ width: 38, height: 38 }}>
                      {goat.sold_by.logo
                        ? <img src={goat.sold_by.logo} alt="" />
                        : <i className={`bi ${goat.sold_by.type === 'house' ? 'bi-house-heart' : 'bi-shop'}`} aria-hidden="true" />}
                    </span>
                    <span>
                      <span className="d-block small text-soft">Sold by</span>
                      <span className="fw-semibold text-ink d-flex align-items-center gap-1">
                        {goat.sold_by.name}
                        {goat.sold_by.is_verified && (
                          <i className="bi bi-patch-check-fill text-brand" title="Verified" aria-label="Verified" />
                        )}
                      </span>
                    </span>
                  </div>

                  {goat.sold_by.slug && (
                    <Link href={`/sellers/${goat.sold_by.slug}`} className="btn btn-quiet btn-sm">
                      View farm
                    </Link>
                  )}
                </div>
              )}

              <BuyBox goat={goat} />
            </div>

            <div className="product-layout__detail">
              <ul className="nav nav-tabs mb-4" role="tablist">
                <li className="nav-item" role="presentation">
                  <button className="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-desc" type="button" role="tab">
                    Description
                  </button>
                </li>
                <li className="nav-item" role="presentation">
                  <button className="nav-link" data-bs-toggle="tab" data-bs-target="#tab-reviews" type="button" role="tab">
                    Reviews ({goat.rating?.count ?? 0})
                  </button>
                </li>
                <li className="nav-item" role="presentation">
                  <button className="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ask" type="button" role="tab">
                    Ask about this goat
                  </button>
                </li>
              </ul>

              <div className="tab-content">
                <div className="tab-pane fade show active" id="tab-desc" role="tabpanel">
                  <div className="prose" dangerouslySetInnerHTML={{ __html: goat.description || '' }} />
                </div>
                <div className="tab-pane fade" id="tab-reviews" role="tabpanel">
                  <ReviewList reviews={goat.reviews || []} rating={goat.rating} />
                </div>
                <div className="tab-pane fade" id="tab-ask" role="tabpanel">
                  <InquiryForm slug={goat.slug} />
                </div>
              </div>

              {specs.length > 0 && (
                <div className="panel mt-4">
                  <h2 className="h6 mb-3">Full specifications</h2>
                  <dl className="spec-list mb-0">
                    {specs.map(([label, value]) => (
                      <div key={label}>
                        <dt>{label}</dt>
                        <dd>{value}</dd>
                      </div>
                    ))}
                  </dl>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {related.length > 0 && (
        <section className="section bg-surface-alt">
          <div className="container">
            <SectionHeader title="Similar goats" action={{ href: '/shop', label: 'View all' }} />
            <GoatGrid goats={related} columns={4} />
          </div>
        </section>
      )}
    </>
  );
}
