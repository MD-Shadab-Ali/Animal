'use client';

import { useRouter, useSearchParams } from 'next/navigation';

export default function SortSelect({ options = [] }) {
  const router = useRouter();
  const searchParams = useSearchParams();

  const change = (value) => {
    const params = new URLSearchParams(searchParams.toString());

    if (value && value !== 'default') params.set('sort', value);
    else params.delete('sort');

    params.delete('page');
    router.push(`/shop?${params.toString()}`, { scroll: false });
  };

  return (
    <div className="d-flex align-items-center gap-2">
      <label htmlFor="sort" className="small text-soft mb-0 text-nowrap">Sort by</label>
      <select
        id="sort"
        className="form-select form-select-sm w-auto"
        value={searchParams.get('sort') || 'default'}
        onChange={(event) => change(event.target.value)}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>{option.label}</option>
        ))}
      </select>
    </div>
  );
}
