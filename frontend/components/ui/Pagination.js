'use client';

import { useRouter, useSearchParams } from 'next/navigation';

export default function Pagination({ meta, basePath = '/shop' }) {
  const router = useRouter();
  const searchParams = useSearchParams();

  if (!meta || meta.last_page <= 1) return null;

  const { current_page: current, last_page: last } = meta;

  const goTo = (page) => {
    const params = new URLSearchParams(searchParams.toString());
    params.set('page', page);
    router.push(`${basePath}?${params.toString()}`);
  };

  const pages = [];
  for (let page = 1; page <= last; page += 1) {
    if (page === 1 || page === last || Math.abs(page - current) <= 1) pages.push(page);
    else if (pages[pages.length - 1] !== '...') pages.push('...');
  }

  return (
    <nav className="d-flex justify-content-center mt-5" aria-label="Pagination">
      <ul className="pagination mb-0 gap-1">
        <li className={`page-item ${current === 1 ? 'disabled' : ''}`}>
          <button className="page-link rounded-3" onClick={() => goTo(current - 1)} disabled={current === 1} aria-label="Previous page">
            <i className="bi bi-chevron-left" aria-hidden="true" />
          </button>
        </li>

        {pages.map((page, index) => (
          <li key={`${page}-${index}`} className={`page-item ${page === current ? 'active' : ''} ${page === '...' ? 'disabled' : ''}`}>
            {page === '...'
              ? <span className="page-link rounded-3">…</span>
              : (
                <button
                  className="page-link rounded-3"
                  onClick={() => goTo(page)}
                  aria-current={page === current ? 'page' : undefined}
                >
                  {page}
                </button>
              )}
          </li>
        ))}

        <li className={`page-item ${current === last ? 'disabled' : ''}`}>
          <button className="page-link rounded-3" onClick={() => goTo(current + 1)} disabled={current === last} aria-label="Next page">
            <i className="bi bi-chevron-right" aria-hidden="true" />
          </button>
        </li>
      </ul>
    </nav>
  );
}
