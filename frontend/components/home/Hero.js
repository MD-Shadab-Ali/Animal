'use client';

import { AnimatePresence, m } from 'motion/react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
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
 * Search-first hero with a center-stage banner carousel.
 *
 * The search bar is the call to action on a marketplace, so it keeps the left
 * column and the admin's banners rotate on the right. Every hero banner the
 * admin has published gets a turn: the headline, description and button on the
 * left belong to the slide on screen, so the four banners are four real
 * messages rather than one message and three unused images.
 *
 * The stage shows the neighbouring slides as narrow, faded peek frames. That is
 * the whole reason it reads as calm -- one frame is clearly the subject, and
 * the next one is a hint rather than a competitor.
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
  const stageRef = useRef(null);

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
    <section className="hero">
      <div className="container hero__inner">
        <div className="row align-items-center g-4 g-lg-5">
          <div className="col-lg-6">
            {slide.subtitle && <span className="eyebrow mb-2 d-block">{slide.subtitle}</span>}

            <h1 className="hero__title">{slide.title || settings.site_name}</h1>

            {slide.description && <p className="hero__lead mb-4">{slide.description}</p>}

            <div className="hero__search mb-3">
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

          <div className="col-lg-6">
            <div
              className="carousel-hero"
              ref={stageRef}
              role="region"
              aria-roledescription="carousel"
              aria-label="Featured banners"
              tabIndex={0}
              onKeyDown={onKeyDown}
              onMouseEnter={() => setPaused(true)}
              onMouseLeave={() => setPaused(false)}
              onFocus={() => setPaused(true)}
              onBlur={() => setPaused(false)}
            >
              <div className="carousel-hero__stage">
                {many && (
                  <div className="carousel-hero__peek carousel-hero__peek--left" aria-hidden="true">
                    {slides[wrap(active - 1, count)].image
                      ? <img src={slides[wrap(active - 1, count)].image} alt="" />
                      : <div className="carousel-hero__blank" />}
                  </div>
                )}

                <figure className="carousel-hero__slide">
                  {/*
                    * mode="sync" is the one case where overlap is the point:
                    * the outgoing banner has to still be there for the
                    * incoming one to fade over, or the frame flashes empty.
                    *
                    * The drag lives on this inner layer rather than on the
                    * figure around it. The figure is what clips -- dragging it
                    * would move the whole framed box and could push the page
                    * sideways on a phone; dragging inside the frame cannot
                    * escape it. touchAction: pan-y leaves vertical scrolling
                    * to the browser, so a swipe down the page still scrolls.
                    */}
                  <AnimatePresence initial={false} mode="sync">
                    <m.div
                      key={active}
                      className="carousel-hero__media"
                      drag={many ? 'x' : false}
                      dragConstraints={{ left: 0, right: 0 }}
                      dragElastic={0.18}
                      dragMomentum={false}
                      style={{ touchAction: 'pan-y' }}
                      onDragStart={() => setPaused(true)}
                      onDragEnd={(event, info) => {
                        setPaused(false);

                        const move = swipeDirection(info);

                        if (move === 1) next();
                        if (move === -1) prev();
                      }}
                      initial={{ opacity: 0, scale: 1.03 }}
                      animate={{ opacity: 1, scale: 1 }}
                      exit={{ opacity: 0 }}
                      transition={TRANSITION.ambient}
                    >
                      {slide.image
                        ? <img src={slide.image} alt={slide.title || ''} draggable={false} />
                        : <div className="hero__media-empty"><i className="bi bi-flower3" aria-hidden="true" /></div>}
                    </m.div>
                  </AnimatePresence>

                  <div className="carousel-hero__scrim" aria-hidden="true" />

                  <figcaption className="carousel-hero__caption">
                    {slide.subtitle && <span className="carousel-hero__eyebrow">{slide.subtitle}</span>}
                    <strong>{slide.title}</strong>
                  </figcaption>
                </figure>

                {many && (
                  <div className="carousel-hero__peek carousel-hero__peek--right" aria-hidden="true">
                    {slides[wrap(active + 1, count)].image
                      ? <img src={slides[wrap(active + 1, count)].image} alt="" />
                      : <div className="carousel-hero__blank" />}
                  </div>
                )}
              </div>

              {many && (
                <>
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
                </>
              )}

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
