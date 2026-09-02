'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useMemo, useState } from 'react';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { ApiError, apiFetch } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';

/** Whole days between two Y-m-d strings, or 0 when the pair makes no sense. */
function nightsBetween(checkIn, checkOut) {
  if (!checkIn || !checkOut) return 0;

  const from = new Date(`${checkIn}T00:00:00`);
  const to = new Date(`${checkOut}T00:00:00`);
  const days = Math.round((to - from) / 86400000);

  return days > 0 ? days : 0;
}

/** Every night a stay occupies: check-in included, check-out never. */
function nightsIn(checkIn, checkOut) {
  const nights = [];
  const count = nightsBetween(checkIn, checkOut);

  for (let i = 0; i < count; i += 1) {
    const night = new Date(`${checkIn}T00:00:00`);
    night.setDate(night.getDate() + i);
    nights.push(night.toISOString().slice(0, 10));
  }

  return nights;
}

const round2 = (value) => Math.round(value * 100) / 100;

/**
 * Choosing a stay and paying for it.
 *
 * The price shown here is worked out in the browser, which is normally exactly
 * the thing not to do -- so it is worth saying why it is safe. Nothing here is
 * sent: the server prices the booking from the room's own rate when it places
 * it, and the figure below exists only so somebody can see what they are about
 * to agree to before they agree to it. If the two ever disagreed, the server's
 * would win and the guest would see it on the booking a second later.
 *
 * The advance is deliberately *not* computed. It comes from a site-wide
 * percentage the farm can change, and a browser quietly getting that wrong is
 * how somebody pays the wrong amount up front. The method's own wording is
 * shown instead, and the exact figure appears on the booking.
 */
export default function BookBox({ room }) {
  const router = useRouter();
  const params = useSearchParams();
  const settings = useSettings();
  const { isAuthenticated, token, user } = useAuth();

  const availability = room.availability || {};
  const pricing = room.pricing || {};
  const sleeps = room.sleeps || {};
  const methods = room.payment_methods || [];

  // Memoised because `|| []` mints a new array on every render, and this is a
  // dependency of the clash check below -- left bare, that check recomputed on
  // every keystroke anywhere in the box.
  const taken = useMemo(() => availability.taken || [], [availability.taken]);
  const earliest = availability.earliest_date;
  const latest = availability.latest_date;

  // Opens on whatever the listing page was already asking about, so a guest
  // who filtered by dates does not pick them twice.
  const [checkIn, setCheckIn] = useState(params.get('check_in') || '');
  const [checkOut, setCheckOut] = useState(params.get('check_out') || '');
  const [guests, setGuests] = useState(Number(params.get('guests')) || sleeps.included || 1);

  const [method, setMethod] = useState(methods[0]?.code || '');
  const chosenMethod = methods.find((entry) => entry.code === method) || methods[0];
  const plans = chosenMethod?.plans || ['full'];
  const [plan, setPlan] = useState(plans[0] || 'full');

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const nights = nightsBetween(checkIn, checkOut);

  // Which of the chosen nights somebody else already has. Named rather than
  // counted, because "3 nights unavailable" sends a guest hunting through a
  // calendar for which three.
  const clashes = useMemo(
    () => nightsIn(checkIn, checkOut).filter((night) => taken.includes(night)),
    [checkIn, checkOut, taken],
  );

  const quote = useMemo(() => {
    if (nights <= 0) return null;

    const roomCharge = round2(pricing.per_night * nights);
    const extraGuests = Math.max(0, guests - (sleeps.included || 1));
    const extraCharge = pricing.extra_guest_fee == null
      ? 0
      : round2(pricing.extra_guest_fee * extraGuests * nights);

    return {
      roomCharge,
      extraGuests,
      extraCharge,
      total: round2(roomCharge + extraCharge),
    };
  }, [nights, guests, pricing.per_night, pricing.extra_guest_fee, sleeps.included]);

  // Every reason this stay cannot be booked, in the order a guest meets them.
  const problem = (() => {
    if (!checkIn || !checkOut) return 'Choose the nights you would like to stay.';
    if (nights <= 0) return 'The day you leave has to be after the day you arrive.';
    if (nights < pricing.min_nights) {
      return `This room is let for at least ${pricing.min_nights} night${pricing.min_nights === 1 ? '' : 's'} at a time.`;
    }
    if (nights > pricing.max_nights) {
      return `This room can be booked for at most ${pricing.max_nights} nights. Call us for a longer stay.`;
    }
    if (guests > sleeps.max) return `This room sleeps ${sleeps.max} at most.`;
    if (clashes.length) {
      return `Already taken: ${clashes.slice(0, 3).map(formatDate).join(', ')}${clashes.length > 3 ? ` and ${clashes.length - 3} more` : ''}.`;
    }
    if (!chosenMethod) return 'There is no way to pay for a room at the moment. Please call us.';
    return null;
  })();

  const book = async () => {
    setSaving(true);
    setError(null);

    try {
      const response = await apiFetch(`/rooms/${room.slug}/bookings`, {
        method: 'POST',
        token,
        body: {
          check_in: checkIn,
          check_out: checkOut,
          guests,
          payment_method: chosenMethod.code,
          payment_plan: plan,
          guest_name: user?.name,
          guest_phone: user?.phone,
          guest_email: user?.email,
        },
      });

      router.push(`/account/bookings/${response.data.booking_number}`);
    } catch (caught) {
      /*
       * The room may have gone while they were deciding, and that is the one
       * error worth being careful with: the server refuses it, nothing is
       * charged, and the guest needs to know the dates are the problem rather
       * than something they typed.
       */
      setError(caught instanceof ApiError
        ? Object.values(caught.errors || {}).flat()[0] || caught.message
        : 'Something went wrong. Please try again.');
      setSaving(false);
    }
  };

  return (
    <div className="buybox buybox--sticky">
      <div className="d-flex align-items-baseline flex-wrap gap-2 mb-3">
        <span className="price-now">{formatMoney(pricing.per_night, settings)}</span>
        <span className="text-soft small">a night</span>
      </div>

      <div className="row g-2 mb-3">
        <div className="col-6">
          <label className="form-label small" htmlFor="check-in">Arrive</label>
          <input
            id="check-in"
            type="date"
            className="form-control"
            value={checkIn}
            min={earliest}
            max={latest}
            onChange={(event) => {
              setCheckIn(event.target.value);
              // A departure now before the arrival is nonsense on screen as
              // well as on the server, so it is cleared rather than argued with.
              if (checkOut && event.target.value >= checkOut) setCheckOut('');
            }}
          />
        </div>

        <div className="col-6">
          <label className="form-label small" htmlFor="check-out">Leave</label>
          <input
            id="check-out"
            type="date"
            className="form-control"
            value={checkOut}
            min={checkIn || earliest}
            max={latest}
            onChange={(event) => setCheckOut(event.target.value)}
          />
        </div>
      </div>

      <p className="text-soft small mb-3">
        Check in from {room.homestay?.check_in_time}, and out by {room.homestay?.check_out_time}.
        The day you leave is not charged as a night.
      </p>

      <div className="mb-3">
        <div className="d-flex align-items-center justify-content-between mb-1">
          <span className="form-label mb-0" id="guests-label">Guests</span>
          <span className="text-soft small">
            {sleeps.included < sleeps.max
              ? `Rate covers ${sleeps.included}, sleeps ${sleeps.max}`
              : `Sleeps ${sleeps.max}`}
          </span>
        </div>

        <div className="qty">
          <button
            type="button"
            onClick={() => setGuests((value) => Math.max(1, value - 1))}
            disabled={guests <= 1}
            aria-label="Fewer guests"
          >
            <i className="bi bi-dash" aria-hidden="true" />
          </button>
          <span aria-live="polite" aria-labelledby="guests-label">{guests}</span>
          <button
            type="button"
            onClick={() => setGuests((value) => Math.min(sleeps.max, value + 1))}
            disabled={guests >= sleeps.max}
            aria-label="More guests"
          >
            <i className="bi bi-plus" aria-hidden="true" />
          </button>
        </div>
      </div>

      {quote && (
        <div className="bookbox__sum mb-3">
          <div>
            <span>
              {formatMoney(pricing.per_night, settings)} × {nights} night{nights === 1 ? '' : 's'}
            </span>
            <span>{formatMoney(quote.roomCharge, settings)}</span>
          </div>

          {quote.extraGuests > 0 && (
            <div>
              <span>
                {quote.extraGuests} extra guest{quote.extraGuests === 1 ? '' : 's'} × {nights} night{nights === 1 ? '' : 's'}
              </span>
              <span>{formatMoney(quote.extraCharge, settings)}</span>
            </div>
          )}

          <div className="bookbox__total">
            <span>Total</span>
            <span>{formatMoney(quote.total, settings)}</span>
          </div>
        </div>
      )}

      {methods.length > 1 && (
        <div className="mb-3">
          <span className="form-label">Pay by</span>
          {methods.map((entry) => (
            <label key={entry.code} className="bookbox__choice">
              <input
                type="radio"
                name="booking-method"
                value={entry.code}
                checked={chosenMethod?.code === entry.code}
                onChange={() => {
                  setMethod(entry.code);
                  // The new method may not offer the plan that was chosen, and
                  // submitting one it does not offer is a refusal at the very
                  // last step.
                  if (!entry.plans.includes(plan)) setPlan(entry.plans[0]);
                }}
              />
              <span>{entry.name}</span>
            </label>
          ))}
        </div>
      )}

      {plans.length > 1 && (
        <div className="mb-3">
          <span className="form-label">When would you like to pay?</span>

          {plans.includes('full') && (
            <label className="bookbox__choice">
              <input
                type="radio"
                name="booking-plan"
                value="full"
                checked={plan === 'full'}
                onChange={() => setPlan('full')}
              />
              <span>
                All of it now
                {quote && <em className="text-soft"> — {formatMoney(quote.total, settings)}</em>}
              </span>
            </label>
          )}

          {plans.includes('advance') && (
            <label className="bookbox__choice">
              <input
                type="radio"
                name="booking-plan"
                value="advance"
                checked={plan === 'advance'}
                onChange={() => setPlan('advance')}
              />
              <span>
                An advance now, the rest when you arrive
                {/* The farm's own wording for its advance. Not recalculated
                    here: a browser quietly getting a site-wide percentage
                    wrong is how somebody pays the wrong amount up front. */}
                {chosenMethod?.advance_label && (
                  <em className="text-soft"> — {chosenMethod.advance_label}</em>
                )}
              </span>
            </label>
          )}
        </div>
      )}

      {error && <div className="alert alert-danger py-2 small">{error}</div>}

      {!isAuthenticated ? (
        <>
          <Link href="/login" className="btn btn-brand w-100">Sign in to book</Link>
          <p className="text-soft small mt-2 mb-0">
            A room is held in somebody’s name, so we need an account to hold it in.
          </p>
        </>
      ) : (
        <>
          <button
            type="button"
            className="btn btn-brand w-100"
            onClick={book}
            disabled={Boolean(problem) || saving}
          >
            {saving ? 'Booking…' : 'Book this room'}
          </button>

          {problem && <p className="text-soft small mt-2 mb-0">{problem}</p>}
        </>
      )}

      {room.homestay?.cancellation_note && (
        <p className="text-soft small mt-3 mb-0">
          <i className="bi bi-shield-check me-1" aria-hidden="true" />
          {room.homestay.cancellation_note}
        </p>
      )}
    </div>
  );
}
