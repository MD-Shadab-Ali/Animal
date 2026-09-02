'use client';

import { useEffect, useRef } from 'react';

const MOBILE_BREAKPOINT = 768;

/**
 * Cursor-reactive glow for a grid of cards.
 *
 * Ported from a GSAP component rather than installed as one. Everything GSAP
 * was doing here is a tween between two numbers, and the browser already tweens
 * numbers: the pointer sets custom properties and the transitions in
 * globals.scss carry them. That keeps the work on the compositor, and keeps
 * this project on one animation system instead of two.
 *
 * The children are whatever the caller renders, server components included.
 * Cards are found by their data attribute rather than passed in, so a grid can
 * stay a server component and still light up.
 */
export default function SpotlightGrid({
  children,
  className = '',
  // Brand green, as an RGB triple so it can sit inside rgb(... / alpha).
  glowColor = '21 128 61',
  spotlightRadius = 320,
  particleCount = 6,
  enableSpotlight = true,
  enableBorderGlow = true,
  enableStars = true,
  enableMagnetism = true,
  clickEffect = true,
  disableAnimations = false,
}) {
  const gridRef = useRef(null);

  useEffect(() => {
    const grid = gridRef.current;
    if (! grid || disableAnimations) return undefined;

    /*
     * Off on phones and for anyone who has asked for less movement. A pointer
     * effect has nothing to say to a touchscreen, and running it there spends
     * battery to no purpose.
     */
    const coarse = window.matchMedia('(pointer: coarse)').matches;
    const stillness = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (coarse || stillness || window.innerWidth <= MOBILE_BREAKPOINT) return undefined;

    const cards = [...grid.querySelectorAll('[data-spotlight-card]')];
    if (! cards.length) return undefined;

    cards.forEach((card) => card.style.setProperty('--glow-color', glowColor));

    let spotlight = null;

    if (enableSpotlight) {
      spotlight = document.createElement('div');
      spotlight.className = 'spotlight-orb';
      spotlight.style.setProperty('--glow-color', glowColor);
      spotlight.style.setProperty('--orb-size', `${spotlightRadius * 2.4}px`);
      document.body.appendChild(spotlight);
    }

    // Proximity bands: full strength within `near`, fading to nothing by `far`.
    const near = spotlightRadius * 0.5;
    const far = spotlightRadius * 0.75;

    let frame = 0;
    let pointer = null;

    const paint = () => {
      frame = 0;
      if (! pointer) return;

      const { clientX, clientY } = pointer;
      const bounds = grid.getBoundingClientRect();
      const inside = clientX >= bounds.left && clientX <= bounds.right
        && clientY >= bounds.top && clientY <= bounds.bottom;

      if (! inside) {
        cards.forEach((card) => card.style.setProperty('--glow-intensity', '0'));
        if (spotlight) spotlight.style.opacity = '0';
        return;
      }

      let closest = Infinity;

      cards.forEach((card) => {
        const rect = card.getBoundingClientRect();
        const centreX = rect.left + rect.width / 2;
        const centreY = rect.top + rect.height / 2;

        // Measured to the card's edge rather than its middle, so a wide card is
        // not treated as further away than a small one beside it.
        const gap = Math.max(
          0,
          Math.hypot(clientX - centreX, clientY - centreY) - Math.max(rect.width, rect.height) / 2,
        );

        closest = Math.min(closest, gap);

        let intensity = 0;
        if (gap <= near) intensity = 1;
        else if (gap <= far) intensity = (far - gap) / (far - near);

        if (enableBorderGlow) {
          card.style.setProperty('--glow-x', `${((clientX - rect.left) / rect.width) * 100}%`);
          card.style.setProperty('--glow-y', `${((clientY - rect.top) / rect.height) * 100}%`);
          card.style.setProperty('--glow-intensity', intensity.toFixed(3));
        }
      });

      if (spotlight) {
        spotlight.style.transform = `translate(${clientX}px, ${clientY}px) translate(-50%, -50%)`;
        spotlight.style.opacity = closest <= near
          ? '1'
          : closest <= far
            ? ((far - closest) / (far - near)).toFixed(3)
            : '0';
      }
    };

    const onPointerMove = (event) => {
      pointer = { clientX: event.clientX, clientY: event.clientY };
      // One paint per frame, however fast the mouse reports.
      if (! frame) frame = requestAnimationFrame(paint);
    };

    const clear = () => {
      pointer = null;
      cards.forEach((card) => card.style.setProperty('--glow-intensity', '0'));
      if (spotlight) spotlight.style.opacity = '0';
    };

    document.addEventListener('mousemove', onPointerMove, { passive: true });
    document.addEventListener('mouseleave', clear);

    /* Per-card: the pull towards the cursor, the sparks, and the ripple. */
    const teardown = cards.map((card) => {
      let sparks = [];

      const drift = (event) => {
        if (! enableMagnetism) return;
        const rect = card.getBoundingClientRect();
        const pullX = ((event.clientX - rect.left) - rect.width / 2) * 0.04;
        const pullY = ((event.clientY - rect.top) - rect.height / 2) * 0.04;
        card.style.setProperty('--magnet-x', `${pullX.toFixed(2)}px`);
        card.style.setProperty('--magnet-y', `${pullY.toFixed(2)}px`);
      };

      const enter = () => {
        if (! enableStars) return;

        const rect = card.getBoundingClientRect();

        sparks = Array.from({ length: particleCount }, (_, i) => {
          const spark = document.createElement('span');
          spark.className = 'spotlight-spark';
          spark.style.setProperty('--glow-color', glowColor);
          spark.style.left = `${Math.random() * rect.width}px`;
          spark.style.top = `${Math.random() * rect.height}px`;
          spark.style.setProperty('--drift-x', `${(Math.random() - 0.5) * 60}px`);
          spark.style.setProperty('--drift-y', `${(Math.random() - 0.5) * 60}px`);
          spark.style.animationDelay = `${i * 90}ms`;
          card.appendChild(spark);
          return spark;
        });
      };

      const leave = () => {
        card.style.setProperty('--magnet-x', '0px');
        card.style.setProperty('--magnet-y', '0px');
        sparks.forEach((spark) => spark.remove());
        sparks = [];
      };

      const ripple = (event) => {
        if (! clickEffect) return;

        const rect = card.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;

        // Reaches the furthest corner, so the wash always covers the card.
        const reach = Math.max(
          Math.hypot(x, y),
          Math.hypot(x - rect.width, y),
          Math.hypot(x, y - rect.height),
          Math.hypot(x - rect.width, y - rect.height),
        );

        const wave = document.createElement('span');
        wave.className = 'spotlight-ripple';
        wave.style.setProperty('--glow-color', glowColor);
        wave.style.width = `${reach * 2}px`;
        wave.style.height = `${reach * 2}px`;
        wave.style.left = `${x - reach}px`;
        wave.style.top = `${y - reach}px`;
        wave.addEventListener('animationend', () => wave.remove());
        card.appendChild(wave);
      };

      card.addEventListener('mousemove', drift, { passive: true });
      card.addEventListener('mouseenter', enter);
      card.addEventListener('mouseleave', leave);
      card.addEventListener('click', ripple);

      return () => {
        card.removeEventListener('mousemove', drift);
        card.removeEventListener('mouseenter', enter);
        card.removeEventListener('mouseleave', leave);
        card.removeEventListener('click', ripple);
        leave();
      };
    });

    return () => {
      if (frame) cancelAnimationFrame(frame);
      document.removeEventListener('mousemove', onPointerMove);
      document.removeEventListener('mouseleave', clear);
      teardown.forEach((off) => off());
      spotlight?.remove();
    };
  }, [
    glowColor, spotlightRadius, particleCount, enableSpotlight,
    enableBorderGlow, enableStars, enableMagnetism, clickEffect, disableAnimations,
  ]);

  return <div ref={gridRef} className={className}>{children}</div>;
}
