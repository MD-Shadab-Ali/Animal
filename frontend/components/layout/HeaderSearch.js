'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import { Suspense, useState } from 'react';

function SearchField() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [term, setTerm] = useState(searchParams.get('search') || '');

  const submit = (event) => {
    event.preventDefault();
    const query = term.trim();
    router.push(query ? `/shop?search=${encodeURIComponent(query)}` : '/shop');
  };

  return (
    <form onSubmit={submit} role="search" className="searchbar">
      <i className="bi bi-search text-soft" aria-hidden="true" />
      <input
        type="search"
        value={term}
        onChange={(event) => setTerm(event.target.value)}
        placeholder="Search by breed, weight or name…"
        aria-label="Search goats"
      />
      <button type="submit" className="searchbar__go" aria-label="Search">
        <i className="bi bi-arrow-right" aria-hidden="true" />
      </button>
    </form>
  );
}

export default function HeaderSearch() {
  return (
    <Suspense fallback={<div className="searchbar"><span className="text-soft small">Search…</span></div>}>
      <SearchField />
    </Suspense>
  );
}
