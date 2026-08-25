'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useSettings } from '@/context/SiteContext';

// What buyers in Nepal actually search for.
const POPULAR = [
  ['Khari khasi', 'Khari'],
  ['Dashain goats', ''],
  ['Jamunapari', 'Jamunapari'],
  ['Dairy does', 'Beetal'],
];

/**
 * Search-first hero. On a marketplace the search bar is the call to action,
 * so it sits above the fold and the admin's banners supply the surrounding
 * copy and imagery.
 */
export default function Hero({ banners = [] }) {
  const router = useRouter();
  const settings = useSettings();
  const [term, setTerm] = useState('');

  const slides = banners.length ? banners : [{
    title: settings.site_name,
    subtitle: null,
    description: settings.site_tagline,
    button_text: 'Browse goats',
    button_link: '/shop',
    image: null,
  }];

  const search = (event) => {
    event.preventDefault();
    const query = term.trim();
    router.push(query ? `/shop?search=${encodeURIComponent(query)}` : '/shop');
  };

  const slide = slides[0];

  return (
    <section className="hero">
      <div className="container hero__inner">
        <div className="row align-items-center g-4 g-lg-5">
          <div className="col-lg-6">
            {slide.subtitle && <span className="eyebrow mb-2 d-block">{slide.subtitle}</span>}

            <h1 className="hero__title">{slide.title || settings.site_name}</h1>

            {slide.description && <p className="hero__lead mb-4">{slide.description}</p>}

            <div className="hero__search mb-3">
              <form onSubmit={search} role="search" className="searchbar mb-3">
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

              <div className="popular-searches">
                <span className="small text-soft me-1">Popular:</span>
                {POPULAR.map(([label, query]) => (
                  <Link
                    key={label}
                    href={query ? `/shop?search=${encodeURIComponent(query)}` : '/shop?category=dashain-goats'}
                    className="chip"
                  >
                    {label}
                  </Link>
                ))}
              </div>
            </div>

            {slide.button_text && slide.button_link && (
              <Link href={slide.button_link} className="btn btn-cta btn-lg">
                {slide.button_text}
                <i className="bi bi-arrow-right" aria-hidden="true" />
              </Link>
            )}
          </div>

          <div className="col-lg-6">
            <div className="hero__media">
              {slide.image
                ? <img src={slide.image} alt={slide.title || ''} />
                : <div className="hero__media-empty"><i className="bi bi-flower3" aria-hidden="true" /></div>}

              <div className="hero__stat">
                <div><b>Vet checked</b><span>Every animal</span></div>
                <div><b>Cash on delivery</b><span>Pay at your door</span></div>
                <div><b>77 districts</b><span>Across Nepal</span></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
