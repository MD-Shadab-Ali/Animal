'use client';

import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';

/**
 * Picking dates without throwing the page away.
 *
 * This was a plain `<form method="get">`, and the reasoning for that was sound
 * as far as it went: no JavaScript needed, the dates end up in the URL where
 * they can be shared, and there is no state to go wrong. What it missed is what
 * a browser actually does with a GET form -- a full document navigation. The
 * whole page is torn down and rebuilt, which on this site also re-runs the
 * preloader overlay, so choosing two dates costs a second of blank screen.
 *
 * So the form stays a real form, `method` and `action` included: with
 * JavaScript off it still submits and still works. With JavaScript on, the
 * submit is intercepted and the same URL is pushed through the router instead,
 * which re-renders the results on the server and swaps them in. Same address
 * bar, same shareable link, same back button -- without the flash.
 *
 * `scroll: false` because the results sit below this form. Letting the router
 * jump to the top would move the page under the pointer at the exact moment
 * somebody is looking at what came back.
 */
export default function StayFilters({ homestay = {}, dates = {} }) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  const [checkIn, setCheckIn] = useState(dates.check_in || '');
  const [checkOut, setCheckOut] = useState(dates.check_out || '');
  const [guests, setGuests] = useState(dates.guests || '');

  const submit = (event) => {
    event.preventDefault();

    const query = new URLSearchParams();
    if (checkIn) query.set('check_in', checkIn);
    if (checkOut) query.set('check_out', checkOut);
    if (guests) query.set('guests', guests);

    const target = query.toString() ? `/homestay?${query}` : '/homestay';

    startTransition(() => router.push(target, { scroll: false }));
  };

  return (
    <form
      method="get"
      action="/homestay"
      onSubmit={submit}
      className="stay-filters mb-4"
      aria-label="Find a room"
    >
      <div>
        <label className="form-label small" htmlFor="filter-check-in">Arrive</label>
        <input
          id="filter-check-in"
          type="date"
          name="check_in"
          className="form-control"
          value={checkIn}
          min={homestay.earliest_date}
          max={homestay.latest_date}
          onChange={(event) => {
            setCheckIn(event.target.value);
            // A departure on or before the arrival is not a stay. Clearing it
            // is kinder than submitting something the server will ignore.
            if (checkOut && event.target.value >= checkOut) setCheckOut('');
          }}
        />
      </div>

      <div>
        <label className="form-label small" htmlFor="filter-check-out">Leave</label>
        <input
          id="filter-check-out"
          type="date"
          name="check_out"
          className="form-control"
          value={checkOut}
          min={checkIn || homestay.earliest_date}
          max={homestay.latest_date}
          onChange={(event) => setCheckOut(event.target.value)}
        />
      </div>

      <div>
        <label className="form-label small" htmlFor="filter-guests">Guests</label>
        <input
          id="filter-guests"
          type="number"
          name="guests"
          className="form-control"
          min="1"
          max="20"
          value={guests}
          placeholder="Any"
          onChange={(event) => setGuests(event.target.value)}
        />
      </div>

      <div className="stay-filters__go">
        <button type="submit" className="btn btn-brand w-100" disabled={pending}>
          {pending ? (
            <>
              <span className="spinner-border spinner-border-sm me-2" aria-hidden="true" />
              Looking…
            </>
          ) : (
            <>
              <i className="bi bi-search me-1" aria-hidden="true" />
              Find a room
            </>
          )}
        </button>
      </div>
    </form>
  );
}
