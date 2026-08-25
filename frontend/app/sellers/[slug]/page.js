import Link from 'next/link';
import { notFound } from 'next/navigation';
import GoatGrid from '@/components/goat/GoatGrid';
import { apiFetchOrNull } from '@/lib/api';
import { buildMetadata } from '@/lib/site';
import { formatDate } from '@/lib/format';

async function getSeller(slug) {
  const response = await apiFetchOrNull(`/sellers/${slug}`, { revalidate: 60 });
  return response?.data ?? null;
}

export async function generateMetadata({ params }) {
  const { slug } = await params;
  const seller = await getSeller(slug);

  if (!seller) return buildMetadata({ title: 'Seller not found' });

  return buildMetadata({
    title: seller.farm_name,
    description: seller.bio || `Goats for sale from ${seller.farm_name} in ${seller.city}.`,
    image: seller.logo,
  });
}

export default async function SellerProfilePage({ params }) {
  const { slug } = await params;
  const seller = await getSeller(slug);

  if (!seller) notFound();

  return (
    <>
      <div
        className="pagehead"
        style={seller.banner ? {
          backgroundImage: `linear-gradient(rgba(20,83,45,.72), rgba(20,83,45,.72)), url(${seller.banner})`,
          backgroundSize: 'cover',
          backgroundPosition: 'center',
        } : undefined}
      >
        <div className="container">
          <nav className="crumbs mb-3" aria-label="Breadcrumb">
            <Link href="/">Home</Link>
            <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
            <Link href="/shop">Shop</Link>
            <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
            <span className={seller.banner ? 'text-white' : 'text-ink'}>{seller.farm_name}</span>
          </nav>

          <div className="d-flex flex-wrap align-items-center gap-3">
            <span className="avatar" style={{ width: 72, height: 72, fontSize: '1.5rem' }}>
              {seller.logo ? <img src={seller.logo} alt="" /> : seller.farm_name.charAt(0)}
            </span>

            <div>
              <h1 className={`section-title mb-1 ${seller.banner ? 'text-white' : ''}`}>
                {seller.farm_name}
              </h1>

              <div className="d-flex flex-wrap align-items-center gap-2">
                {seller.is_verified && (
                  <span className="badge-verified">
                    <i className="bi bi-patch-check-fill" aria-hidden="true" /> Verified seller
                  </span>
                )}
                <span className={`small ${seller.banner ? 'text-white-50' : 'text-soft'}`}>
                  <i className="bi bi-geo-alt me-1" aria-hidden="true" />
                  {seller.area ? `${seller.area}, ` : ''}{seller.city}
                </span>
                {seller.member_since && (
                  <span className={`small ${seller.banner ? 'text-white-50' : 'text-soft'}`}>
                    Selling since {formatDate(seller.member_since)}
                  </span>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="section">
        <div className="container">
          {seller.bio && (
            <div className="panel mb-4">
              <h2 className="h6 mb-2">About this farm</h2>
              <p className="mb-0 text-soft">{seller.bio}</p>
            </div>
          )}

          <h2 className="section-title h5 mb-4">
            {seller.listings_count ?? seller.goats?.length ?? 0} goat
            {(seller.listings_count ?? 0) === 1 ? '' : 's'} available
          </h2>

          <GoatGrid
            goats={seller.goats || []}
            columns={4}
            emptyMessage="This seller has nothing listed at the moment."
          />
        </div>
      </div>
    </>
  );
}
