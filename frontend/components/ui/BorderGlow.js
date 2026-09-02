'use client';

import { useCallback, useEffect, useRef } from 'react';

/*
 * A card whose border lights along the edge nearest the pointer.
 *
 * Ported from React Bits. It needed no dependencies to begin with -- the whole
 * effect is custom properties and masks -- so the only changes are the ones
 * this project's conventions ask for: the styles live in globals.scss beside
 * everything else rather than in a component-level .css file, and the palette
 * is the brand's rather than the demo's purple.
 */

function parseHsl(value) {
  const match = String(value).match(/([\d.]+)\s*([\d.]+)%?\s*([\d.]+)%?/);
  if (! match) return { h: 145, s: 60, l: 40 };

  return { h: parseFloat(match[1]), s: parseFloat(match[2]), l: parseFloat(match[3]) };
}

/*
 * The glow is one colour at seven strengths, and the box-shadow stack in the
 * stylesheet reads them by name. Built here so a caller passes one colour
 * rather than seven.
 */
function glowVars(glowColor, intensity) {
  const { h, s, l } = parseHsl(glowColor);
  const base = `${h}deg ${s}% ${l}%`;
  const steps = [['', 100], ['-60', 60], ['-50', 50], ['-40', 40], ['-30', 30], ['-20', 20], ['-10', 10]];

  return Object.fromEntries(steps.map(([suffix, opacity]) => [
    `--glow-color${suffix}`,
    `hsl(${base} / ${Math.min(opacity * intensity, 100)}%)`,
  ]));
}

const GRADIENT_POSITIONS = ['80% 55%', '69% 34%', '8% 6%', '41% 38%', '86% 85%', '82% 18%', '51% 4%'];
const GRADIENT_KEYS = [
  '--gradient-one', '--gradient-two', '--gradient-three', '--gradient-four',
  '--gradient-five', '--gradient-six', '--gradient-seven',
];
const COLOUR_MAP = [0, 1, 2, 0, 1, 2, 1];

/** Seven radial gradients drawn from three colours, for the mesh border. */
function gradientVars(colors) {
  const vars = Object.fromEntries(GRADIENT_KEYS.map((key, i) => [
    key,
    `radial-gradient(at ${GRADIENT_POSITIONS[i]}, ${colors[Math.min(COLOUR_MAP[i], colors.length - 1)]} 0px, transparent 50%)`,
  ]));

  vars['--gradient-base'] = `linear-gradient(${colors[0]} 0 100%)`;

  return vars;
}

/** A pale surface needs normal blending; the original is built for dark cards. */
function isLightSurface(color) {
  const value = String(color).trim().replace('#', '');
  if (! /^[\da-f]{3}([\da-f]{3})?$/i.test(value)) return false;

  const hex = value.length === 3 ? value.split('').map((c) => c + c).join('') : value;
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16));

  return r * 0.2126 + g * 0.7152 + b * 0.0722 > 180;
}

export default function BorderGlow({
  children,
  className = '',
  edgeSensitivity = 30,
  // Brand green, as the "H S L" triple the original expects.
  glowColor = '145 60% 40%',
  backgroundColor = '#ffffff',
  borderRadius = 16,
  glowRadius = 40,
  glowIntensity = 1,
  coneSpread = 25,
  fillOpacity = 0.5,
  colors = ['#15803D', '#22C55E', '#A16207'],
}) {
  const cardRef = useRef(null);

  /*
   * Two numbers describe the whole effect: how close to an edge the pointer is,
   * and which way it lies from the middle. The stylesheet does the rest.
   */
  const onPointerMove = useCallback((event) => {
    const card = cardRef.current;
    if (! card) return;

    const rect = card.getBoundingClientRect();
    const x = event.clientX - rect.left - rect.width / 2;
    const y = event.clientY - rect.top - rect.height / 2;

    const reachX = x === 0 ? Infinity : (rect.width / 2) / Math.abs(x);
    const reachY = y === 0 ? Infinity : (rect.height / 2) / Math.abs(y);
    const edge = Math.min(Math.max(1 / Math.min(reachX, reachY), 0), 1);

    const angle = (x === 0 && y === 0)
      ? 0
      : (Math.atan2(y, x) * (180 / Math.PI) + 90 + 360) % 360;

    card.style.setProperty('--edge-proximity', (edge * 100).toFixed(2));
    card.style.setProperty('--cursor-angle', `${angle.toFixed(2)}deg`);
  }, []);

  /*
   * A pointer that leaves the card would otherwise leave the glow frozen where
   * it was last seen, because nothing else resets it.
   */
  const onPointerLeave = useCallback(() => {
    cardRef.current?.style.setProperty('--edge-proximity', '0');
  }, []);

  useEffect(() => onPointerLeave, [onPointerLeave]);

  return (
    <div
      ref={cardRef}
      onPointerMove={onPointerMove}
      onPointerLeave={onPointerLeave}
      className={`border-glow${isLightSurface(backgroundColor) ? ' border-glow--light' : ''} ${className}`}
      style={{
        '--card-bg': backgroundColor,
        '--edge-sensitivity': edgeSensitivity,
        '--border-radius': `${borderRadius}px`,
        '--glow-padding': `${glowRadius}px`,
        '--cone-spread': coneSpread,
        '--fill-opacity': fillOpacity,
        ...glowVars(glowColor, glowIntensity),
        ...gradientVars(colors),
      }}
    >
      <span className="border-glow__edge" />
      <div className="border-glow__inner">{children}</div>
    </div>
  );
}
