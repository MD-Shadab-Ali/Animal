'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useState } from 'react';
import { useSettings } from '@/context/SiteContext';
import { formatMoney } from '@/lib/format';

/**
 * Filter options come from live stock, so a new breed appears here as soon as
 * the admin publishes a goat with it.
 */
export default function ShopFilters({ filters, categories }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const settings = useSettings();

  const priceMax = Math.ceil(filters?.price?.max || 0);
  const priceMin = Math.floor(filters?.price?.min || 0);

  // The URL is the source of truth; local state only holds the value mid-drag,
  // so clearing filters elsewhere moves the slider without an effect.
  const [dragging, setDragging] = useState(null);
  const maxPrice = dragging ?? (searchParams.get('max_price') || String(priceMax));

  const apply = useCallback((updates) => {
    const params = new URLSearchParams(searchParams.toString());

    Object.entries(updates).forEach(([key, value]) => {
      if (value === null || value === '' || value === undefined) params.delete(key);
      else params.set(key, value);
    });

    params.delete('page');
    setDragging(null);
    router.push(`/shop?${params.toString()}`, { scroll: false });
  }, [router, searchParams]);

  const current = (key) => searchParams.get(key) || '';

  return (
    <aside className="filters" aria-label="Filter goats">
      <div className="filters__group">
        <h3>Category</h3>
        <div className="d-grid gap-1">
          <button
            type="button"
            className={`filter-option ${!current('category') ? 'is-active' : ''}`}
            onClick={() => apply({ category: null })}
          >
            All categories
          </button>

          {categories.map((category) => (
            <button
              key={category.slug}
              type="button"
              className={`filter-option ${current('category') === category.slug ? 'is-active' : ''}`}
              onClick={() => apply({ category: category.slug })}
            >
              <span>{category.name}</span>
              {category.goats_count !== undefined && <small>{category.goats_count}</small>}
            </button>
          ))}
        </div>
      </div>

      {filters?.breeds?.length > 0 && (
        <div className="filters__group">
          <h3>Breed</h3>
          <label className="visually-hidden" htmlFor="filter-breed">Breed</label>
          <select
            id="filter-breed"
            className="form-select"
            value={current('breed')}
            onChange={(event) => apply({ breed: event.target.value })}
          >
            <option value="">Any breed</option>
            {filters.breeds.map((breed) => (
              <option key={breed} value={breed}>{breed}</option>
            ))}
          </select>
        </div>
      )}

      <div className="filters__group">
        <h3>Gender</h3>
        <div className="d-flex gap-2 flex-wrap">
          {[['', 'Any'], ['male', 'Male'], ['female', 'Female']].map(([value, label]) => (
            <button
              key={value || 'any'}
              type="button"
              className={`chip ${current('gender') === value ? 'is-active' : ''}`}
              onClick={() => apply({ gender: value || null })}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {priceMax > 0 && (
        <div className="filters__group">
          <h3>Maximum price</h3>
          <label className="visually-hidden" htmlFor="filter-price">Maximum price</label>
          <input
            id="filter-price"
            type="range"
            className="form-range"
            min={priceMin}
            max={priceMax}
            step={1000}
            value={maxPrice}
            onChange={(event) => setDragging(event.target.value)}
            onMouseUp={() => apply({ max_price: maxPrice })}
            onTouchEnd={() => apply({ max_price: maxPrice })}
            onKeyUp={(event) => event.key === 'Enter' && apply({ max_price: maxPrice })}
          />
          <div className="d-flex justify-content-between small">
            <span className="text-soft">{formatMoney(priceMin, settings)}</span>
            <strong className="text-brand">{formatMoney(maxPrice, settings)}</strong>
          </div>
        </div>
      )}

      <div className="filters__group">
        <div className="form-check">
          <input
            className="form-check-input"
            type="checkbox"
            id="filter-stock"
            checked={current('in_stock') === '1'}
            onChange={(event) => apply({ in_stock: event.target.checked ? '1' : null })}
          />
          <label className="form-check-label" htmlFor="filter-stock">Available only</label>
        </div>
      </div>
    </aside>
  );
}
