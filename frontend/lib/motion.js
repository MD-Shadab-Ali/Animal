/**
 * One vocabulary for every animation on the site.
 *
 * The stylesheet already has --speed and --ease for CSS transitions; these are
 * the same intent expressed for Motion, so a panel that slides open in JS and
 * a button that lifts on hover in CSS are moving to the same rhythm rather
 * than each inventing their own.
 *
 * Reduced motion is not handled here. <MotionConfig reducedMotion="user"> in
 * app/providers.js switches every transform and layout animation off at the
 * source for anyone who asked their OS to calm things down, so no component
 * has to remember to check.
 */

/** Seconds. Anything a person is waiting on stays under a fifth of a second. */
export const DURATION = {
  fast: 0.18,
  normal: 0.28,
  slow: 0.42,
  // The hero crossfade is deliberately long: it is ambient, not a response.
  ambient: 0.6,
};

export const EASE = {
  // Matches --ease in globals.scss.
  smooth: [0.22, 1, 0.36, 1],
  sharp: [0.4, 0, 0.2, 1],
};

export const TRANSITION = {
  fast: { duration: DURATION.fast, ease: EASE.smooth },
  normal: { duration: DURATION.normal, ease: EASE.smooth },
  slow: { duration: DURATION.slow, ease: EASE.smooth },
  ambient: { duration: DURATION.ambient, ease: EASE.smooth },
  // Layout reflow reads better as physics than as a curve.
  layout: { type: 'spring', stiffness: 420, damping: 38, mass: 0.7 },
};

/* ── Shared variants ─────────────────────────────────────── */

/** Modal and drawer scrims. Opacity only, so nothing is ever dragged about. */
export const scrim = {
  hidden: { opacity: 0 },
  shown: { opacity: 1 },
};

/** A dialog panel: arrives from just below its resting place, barely scaled. */
export const dialogPanel = {
  hidden: { opacity: 0, scale: 0.97, y: 8 },
  shown: { opacity: 1, scale: 1, y: 0 },
};

/** The mobile drawer, off the right-hand edge. */
export const drawerPanel = {
  hidden: { x: '100%' },
  shown: { x: 0 },
};

/**
 * Checkout steps.
 *
 * Vertical rather than horizontal on purpose: a sideways slide inside a
 * Bootstrap column can push past the viewport and flash a horizontal
 * scrollbar on a phone, and the step change is already accompanied by a
 * scroll to the top of the page, which is itself a vertical move.
 */
export const stepPane = {
  enter: (forward) => ({ opacity: 0, y: forward ? 16 : -16 }),
  centre: { opacity: 1, y: 0 },
  exit: (forward) => ({ opacity: 0, y: forward ? -16 : 16 }),
};

/** A removable chip, and anything else that pops out of a list. */
export const chipItem = {
  hidden: { opacity: 0, scale: 0.85 },
  shown: { opacity: 1, scale: 1 },
};

/** A row leaving a list: away to the left, so it reads as "gone", not "hidden". */
export const rowExit = {
  hidden: { opacity: 0, x: -24 },
  shown: { opacity: 1, x: 0 },
};

/**
 * A disclosure that pushes the content below it down.
 *
 * height is a layout property and the usual advice is to leave it alone, but
 * it is the only honest way to reveal a field that was not taking up room a
 * moment ago. The blocks this is used on are one input tall, so the reflow
 * costs nothing -- and the alternative, a fixed height, means guessing.
 */
export const disclosure = {
  hidden: { height: 0, opacity: 0 },
  shown: { height: 'auto', opacity: 1 },
};

/**
 * Turns a finished drag into a decision.
 *
 * Velocity is folded into the distance so a short flick counts the same as a
 * slow, deliberate pull -- which is how a carousel actually gets swiped on a
 * phone. Returns 1 for "next", -1 for "previous", 0 for "not far enough".
 */
export function swipeDirection(info, threshold = 60) {
  const power = info.offset.x + info.velocity.x * 0.2;

  if (power < -threshold) return 1;
  if (power > threshold) return -1;

  return 0;
}
