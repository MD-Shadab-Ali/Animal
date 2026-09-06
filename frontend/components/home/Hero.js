'use client';

import { AnimatePresence, m } from 'motion/react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { useSettings } from '@/context/SiteContext';
import { TRANSITION, swipeDirection } from '@/lib/motion';

// What buyers in Nepal actually search for.
const POPULAR = [
  ['Khari khasi', 'Khari'],
  ['Dashain goats', ''],
  ['Jamunapari', 'Jamunapari'],
  ['Dairy does', 'Beetal'],
];

const AUTOPLAY_MS = 6000;

/** Wraps an index onto the slide list, so prev from 0 lands on the last one. */
const wrap = (index, length) => ((index % length) + length) % length;

/**
 * Search-first hero, staged on a full-bleed banner.
 *
 * The banner is the hero now: the active slide fills the whole band edge to
 * edge and the copy sits on top of it, rather than the two splitting the width
 * as a column of text beside a framed photo. A marketplace hero has one job --
 * make the animal feel real and the search box feel obvious -- and a photo the
 * size of the screen does the first half far better than a card can.
 *
 * Legibility is a gradient, not a flat wash: a dark ramp runs in from the left
 * behind the headline, a second lifts the bottom edge under the call to
 * action, and the picture stays clear of both on the right.
 *
 * Everything the carousel could do before, it still does: autoplay with a
 * progress bar, dots, thumbnails, arrow keys and a swipe. The swipe lives on
 * the background layer, so .hero__inner passes the pointer through and only
 * the things worth touching -- search box, chips, buttons -- take it back.
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

  const count = slides.length;
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  const [motionOk, setMotionOk] = useState(true);

  const go = useCallback((index) => setActive(wrap(index, count)), [count]);
  const next = useCallback(() => setActive((i) => wrap(i + 1, count)), [count]);
  const prev = useCallback(() => setActive((i) => wrap(i - 1, count)), [count]);

  // Someone who asked the OS to calm animations down gets a carousel that
  // holds still: no autoplay, no slide transition. They can still step
  // through it themselves.
  useEffect(() => {
    const query = window.matchMedia('(prefers-reduced-motion: reduce)');
    const sync = () => setMotionOk(!query.matches);
    sync();
    query.addEventListener('change', sync);
    return () => query.removeEventListener('change', sync);
  }, []);

  useEffect(() => {
    if (count < 2 || paused || !motionOk) return undefined;
    const timer = setTimeout(next, AUTOPLAY_MS);
    return () => clearTimeout(timer);
  }, [active, count, paused, motionOk, next]);

  const onKeyDown = (event) => {
    if (event.key === 'ArrowLeft') { event.preventDefault(); prev(); }
    if (event.key === 'ArrowRight') { event.preventDefault(); next(); }
  };

  const search = (event) => {
    event.preventDefault();
    const query = term.trim();
    router.push(query ? `/shop?search=${encodeURIComponent(query)}` : '/shop');
  };

  const slide = slides[active];
  const many = count > 1;

  return (
    <section
      className="hero hero--immersive"
      role="region"
      aria-roledescription="carousel"
      aria-label="Featured banners"
      tabIndex={0}
      onKeyDown={onKeyDown}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
    >
      {/*
        * mode="sync" is the one case where overlap is the point: the outgoing
        * banner has to still be there for the incoming one to fade over, or
        * the band flashes empty.
        *
        * touchAction: pan-y leaves vertical scrolling to the browser, so a
        * swipe down the page still scrolls the page.
        */}
      <div className="hero__bg">
        <AnimatePresence initial={false} mode="sync">
          <m.div
            key={active}
            className="hero__bg-frame"
            drag={many ? 'x' : false}
            dragConstraints={{ left: 0, right: 0 }}
            dragElastic={0.14}
            dragMomentum={false}
            style={{ touchAction: 'pan-y' }}
            onDragStart={() => setPaused(true)}
            onDragEnd={(event, info) => {
              setPaused(false);

              const move = swipeDirection(info);

              if (move === 1) next();
              if (move === -1) prev();
            }}
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={TRANSITION.ambient}
          >
            {slide.image
              ? <img src={slide.image} alt="" draggable={false} className="hero__bg-img" />
              : <div className="hero__media-empty"><i className="bi bi-flower3" aria-hidden="true" /></div>}
          </m.div>
        </AnimatePresence>
      </div>

      <div className="hero__veil" aria-hidden="true" />

      <div className="container hero__inner">
        <div className="row g-4 g-lg-5 align-items-end">
          <div className="col-lg-7 hero__copy">
            {slide.subtitle && <span className="eyebrow mb-2 d-block">{slide.subtitle}</span>}

            <h1 className="hero__title">{slide.title || settings.site_name}</h1>

            {slide.description && <p className="hero__lead mb-3">{slide.description}</p>}

            {/*
              * Hover-pause is on the two panels rather than on the whole band.
              * It used to sit on the framed carousel, which was a slice of the
              * page; the band is the page, so the same handler there would
              * stop the rotation for anyone whose cursor happened to be resting
              * over the top of the window -- which is most people, most of the
              * time. Hovering the search box or the thumbnails is a real signal
              * that someone is busy with this slide. Focus still pauses the
              * whole section: that one is always deliberate.
              */}
            <div
              className="hero__search mb-3"
              onMouseEnter={() => setPaused(true)}
              onMouseLeave={() => setPaused(false)}
            >
              <form onSubmit={search} role="search" className="searchbar mb-3">
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
              <Link href={slide.button_link} className="btn btn-cta btn-lg btn-beam">
                {slide.button_text}
                <i className="bi bi-arrow-right" aria-hidden="true" />
              </Link>
            )}
          </div>

          {many && (
            <div className="col-lg-5">
              <div
                className="carousel-hero hero__controls"
                onMouseEnter={() => setPaused(true)}
                onMouseLeave={() => setPaused(false)}
              >
                <div className="carousel-hero__thumbs">
                  {slides.map((item, index) => (
                    <button
                      key={item.id ?? index}
                      type="button"
                      className={`carousel-hero__thumb ${index === active ? 'is-active' : ''}`}
                      onClick={() => go(index)}
                      aria-label={`Show ${item.title || `banner ${index + 1}`}`}
                    >
                      {item.image
                        ? <img src={item.image} alt="" />
                        : <span className="carousel-hero__blank" />}
                    </button>
                  ))}
                </div>

                {/* Keyed on the slide so the fill restarts its run each time. */}
                <div className="carousel-hero__progress">
                  <span
                    key={active}
                    className={paused || !motionOk ? '' : 'is-running'}
                    style={{ animationDuration: `${AUTOPLAY_MS}ms` }}
                  />
                </div>

                <div className="carousel-hero__dots" role="tablist" aria-label="Choose a banner">
                  {slides.map((item, index) => (
                    <button
                      key={item.id ?? index}
                      type="button"
                      role="tab"
                      className={`carousel-hero__dot ${index === active ? 'is-active' : ''}`}
                      aria-selected={index === active}
                      aria-label={item.title || `Banner ${index + 1}`}
                      onClick={() => go(index)}
                    />
                  ))}
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
