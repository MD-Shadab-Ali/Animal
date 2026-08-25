'use client';

import { useRouter, useSearchParams } from 'next/navigation';

const LABELS = {
  category: 'Category',
  breed: 'Breed',
  gender: 'Gender',
  max_price: 'Under',
  min_price: 'Over',
  search: 'Search',
  in_stock: 'Availability',
};

/** Removable chips make it obvious why a result set is small. */
export default function ActiveFilters() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const active = Object.keys(LABELS)
    .filter((key) => searchParams.get(key))
    .map((key) => ({
      key,
      label: LABELS[key],
      value: key === 'in_stock' ? 'In stock' : searchParams.get(key),
    }));

  if (!active.length) return null;

  const drop = (key) => {
    const params = new URLSearchParams(searchParams.toString());
    params.delete(key);
    params.delete('page');
    router.push(`/shop?${params.toString()}`, { scroll: false });
  };

  return (
    <div className="active-filters mb-4">
      {active.map(({ key, label, value }) => (
        <button key={key} type="button" className="chip is-active" onClick={() => drop(key)}>
          <span className="opacity-75">{label}:</span> {value}
          <i className="bi bi-x-lg" aria-hidden="true" />
          <span className="visually-hidden">Remove filter</span>
        </button>
      ))}

      <button type="button" className="chip" onClick={() => router.push('/shop')}>
        Clear all
      </button>
    </div>
  );
}
