'use client';

import { AnimatePresence, m } from 'motion/react';
import { useRouter, useSearchParams } from 'next/navigation';
import { TRANSITION, chipItem } from '@/lib/motion';

const LABELS = {
  category: 'Category',
  breed: 'Breed',
  gender: 'Gender',
  max_price: 'Under',
  min_price: 'Over',
  search: 'Search',
  in_stock: 'Availability',
};

/** Removable chips make it obvious why a result set is small. */
export default function ActiveFilters() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const active = Object.keys(LABELS)
    .filter((key) => searchParams.get(key))
    .map((key) => ({
      key,
      label: LABELS[key],
      value: key === 'in_stock' ? 'In stock' : searchParams.get(key),
    }));

  const drop = (key) => {
    const params = new URLSearchParams(searchParams.toString());
    params.delete(key);
    params.delete('page');
    router.push(`/shop?${params.toString()}`, { scroll: false });
  };

  return (
    /*
     * The row stays mounted even with nothing in it. Returning null once the
     * last filter went meant the last chip was torn out of the DOM rather than
     * animated out of it -- the one removal where the feedback matters most,
     * because it is the one that changes the whole result set.
     *
     * It carries its own bottom margin instead of a utility class so it can
     * give that margin back on the way out, rather than leaving a 1.5rem hole
     * above the grid.
     */
    <m.div
      className="active-filters"
      initial={false}
      animate={{ marginBottom: active.length ? '1.5rem' : '0rem' }}
      transition={TRANSITION.normal}
    >
      {/*
        * popLayout takes a departing chip out of the flow at once, so the
        * chips after it slide across into the space instead of waiting for it
        * to finish shrinking.
        */}
      <AnimatePresence initial={false} mode="popLayout">
        {active.map(({ key, label, value }) => (
          <m.button
            key={key}
            layout
            type="button"
            className="chip is-active"
            variants={chipItem}
            initial="hidden"
            animate="shown"
            exit="hidden"
            transition={TRANSITION.fast}
            onClick={() => drop(key)}
          >
            <span className="opacity-75">{label}:</span> {value}
            <i className="bi bi-x-lg" aria-hidden="true" />
            <span className="visually-hidden">Remove filter</span>
          </m.button>
        ))}

        {active.length > 0 && (
          <m.button
            key="clear-all"
            layout
            type="button"
            className="chip"
            variants={chipItem}
            initial="hidden"
            animate="shown"
            exit="hidden"
            transition={TRANSITION.fast}
            onClick={() => router.push('/shop')}
          >
            Clear all
          </m.button>
        )}
      </AnimatePresence>
    </m.div>
  );
}
