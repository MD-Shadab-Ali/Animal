import Link from 'next/link';
import { Suspense } from 'react';
import ActiveFilters from '@/components/goat/ActiveFilters';
import GoatGrid from '@/components/goat/GoatGrid';
import ShopFilters from '@/components/goat/ShopFilters';
import SortSelect from '@/components/goat/SortSelect';
import GoatGridSkeleton from '@/components/ui/GoatGridSkeleton';
import Pagination from '@/components/ui/Pagination';
import { apiFetch } from '@/lib/api';
import { buildMetadata } from '@/lib/site';

export async function generateMetadata({ searchParams }) {
  const params = await searchParams;

  return buildMetadata({
    title: params?.search ? `Search: ${params.search}` : 'Shop goats',
    description: 'Browse every goat we have available — filter by breed, weight, age and price.',
  });
}

const FILTER_KEYS = [
  'category', 'breed', 'gender', 'min_price', 'max_price',
  'min_weight', 'max_weight', 'search', 'sort', 'in_stock', 'page',
];

export default async function ShopPage({ searchParams }) {
  const params = await searchParams;

  const query = new URLSearchParams();
  FILTER_KEYS.forEach((key) => {
    if (params?.[key]) query.set(key, params[key]);
  });

  const [goatsResponse, filtersResponse, categoriesResponse] = await Promise.all([
    apiFetch(`/goats?${query.toString()}`, { revalidate: 30, tags: ['goats'] }),
    apiFetch('/goats/filters', { revalidate: 300 }),
    apiFetch('/categories', { revalidate: 300 }),
  ]);

  const goats = goatsResponse.data || [];
  const meta = goatsResponse.meta;
  const filters = filtersResponse.data;
  const categories = categoriesResponse.data || [];

  const activeCategory = categories.find((category) => category.slug === params?.category);
  const total = meta?.total ?? goats.length;

  const heading = activeCategory?.name
    || (params?.search ? `Results for “${params.search}”` : 'All goats');

  return (
    <>
      <div className="pagehead">
        <div className="container">
          <nav className="crumbs mb-2" aria-label="Breadcrumb">
            <Link href="/">Home</Link>
            <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
            <span className="text-ink">Shop</span>
          </nav>

          <h1 className="section-title mb-1">{heading}</h1>
          <p className="section-sub">
            {activeCategory?.description || 'Every animal is vet-checked and weighed before it is listed.'}
          </p>
        </div>
      </div>

      <div className="section">
        <div className="container">
          <div className="row g-4">
            <div className="col-lg-3">
              {/* Filters collapse behind a button on small screens. */}
              <button
                className="btn btn-quiet w-100 d-lg-none mb-3"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#shopFilters"
                aria-expanded="false"
                aria-controls="shopFilters"
              >
                <i className="bi bi-sliders" aria-hidden="true" /> Filters
              </button>

              <div className="collapse d-lg-block filters-rail" id="shopFilters">
                <Suspense fallback={<div className="filters"><div className="skeleton skeleton-line" style={{ width: '60%' }} /></div>}>
                  <ShopFilters filters={filters} categories={categories} />
                </Suspense>
              </div>
            </div>

            <div className="col-lg-9">
              <Suspense fallback={null}>
                <ActiveFilters />
              </Suspense>

              <div className="toolbar">
                <p className="text-soft small mb-0">
                  <strong className="text-ink">{total}</strong> goat{total === 1 ? '' : 's'} found
                </p>
                <Suspense fallback={null}>
                  <SortSelect options={filters?.sorts || []} />
                </Suspense>
              </div>

              <Suspense fallback={<GoatGridSkeleton count={9} columns={3} />}>
                <GoatGrid goats={goats} columns={3} />
              </Suspense>

              <Suspense fallback={null}>
                <Pagination meta={meta} basePath="/shop" />
              </Suspense>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
