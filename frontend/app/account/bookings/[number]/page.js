'use client';

import Link from 'next/link';
import { useParams, useSearchParams } from 'next/navigation';
import { useCallback, useState } from 'react';
import toast from 'react-hot-toast';
import BookingPayment from '@/components/account/BookingPayment';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { ApiError, apiFetch } from '@/lib/api';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { useLiveRefresh } from '@/lib/useLiveRefresh';

/**
 * What each status is called when the guest reads it.
 *
 * Not the admin's wording. "Placed" and "Confirmed" happen to read much the
 * same either way, but the last two do not: staff check somebody in and out,
 * while the guest arrives and leaves.
 */
const GUEST_LABELS = {
  placed: 'Booked',
  confirmed: 'Confirmed',
  checked_in: 'You are here',
  checked_out: 'Stay finished',
  cancelled: 'Cancelled',
};

const STEPS = [
  ['placed', GUEST_LABELS.placed, 'bi-calendar-check'],
  ['confirmed', GUEST_LABELS.confirmed, 'bi-patch-check'],
  ['checked_in', GUEST_LABELS.checked_in, 'bi-door-open'],
  ['checked_out', GUEST_LABELS.checked_out, 'bi-check-circle'],
];

const FLOW = STEPS.map(([status]) => status);

/**
 * The story of a booking, rather than the log of it.
 *
 * `booking.history` is an audit trail and records every move, including ones
 * that were undone -- a status set by mistake and put back, or a stay a member
 * of staff nudged and then corrected. Staff need all of that and see it in the
 * admin. A guest reading their own booking does not: printing it verbatim
 * produced "Confirmed / You are here / Confirmed / Booked", which describes a
 * stay that lurched forwards and back for reasons the guest has no way to know.
 *
 * Two rules clean it up without hiding anything that actually happened:
 *
 *  - each status appears once, stamped with the *first* time it was reached,
 *    because that is when the thing really happened;
 *  - anything ahead of where the booking now stands is dropped, because a step
 *    the booking has since moved back from did not stick.
 *
 * A cancellation always survives both rules. It is the one event a guest must
 * see no matter where it sits.
 */
function guestUpdates(history = [], status) {
  const now = FLOW.indexOf(status);
  const inFlow = now !== -1;
  const kept = new Map();

  // Newest first, so writing every occurrence leaves the oldest -- the moment
  // each status was first reached.
  history.forEach((entry) => {
    if (entry.to !== 'cancelled' && inFlow && FLOW.indexOf(entry.to) > now) return;

    kept.set(entry.to, entry);
  });

  return Array.from(kept.values()).sort((a, b) => new Date(b.at) - new Date(a.at));
}

export default function BookingDetailPage() {
  const { number } = useParams();
  const searchParams = useSearchParams();
  const { token } = useAuth();
  const settings = useSettings();

  const [booking, setBooking] = useState(null);
  const [cancelling, setCancelling] = useState(false);
  const [confirmingCancel, setConfirmingCancel] = useState(false);

  /*
   * What the payment provider sent them back with. Only ever a label: the
   * booking's own figures come from the server, which decided them by asking
   * the provider directly rather than by trusting this query string.
   */
  const paymentResult = searchParams.get('payment');

  // Optional chaining because this is read before the loading guard below has
  // proved there is a booking to read it from.
  const showPaymentResult = ['placed', 'confirmed'].includes(booking?.status);

  const load = useCallback(async () => {
    if (!token) return;

    try {
      const response = await apiFetch(`/bookings/${number}`, { token });
      setBooking(response.data);
    } catch {
      setBooking(false);
    }
  }, [token, number]);

  // Staff confirm the money and check people in, and none of that reaches an
  // open tab on its own.
  useLiveRefresh(load, { immediate: true, enabled: Boolean(token) });

  /*
   * No container and no section on any of these.
   *
   * app/account/layout.js already wraps everything under /account in
   * `.section > .container`, and .section is padding: clamp(2.5rem, 6vw,
   * 4.5rem). Adding a second one here applied that padding twice -- up to
   * 144px of dead space above the breadcrumb -- and nested a container inside a
   * container, narrowing the page for no reason. The order page next door roots
   * at a bare grid for exactly this reason.
   */
  if (booking === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (booking === false) {
    return (
      <div className="panel text-center py-5">
        <h1 className="h5 mb-1">Booking not found</h1>
        <p className="text-soft small mb-3">It may belong to another account.</p>
        <Link href="/account/bookings" className="btn btn-brand btn-sm">Your stays</Link>
      </div>
    );
  }

  const cancel = async () => {
    setCancelling(true);

    try {
      await apiFetch(`/bookings/${booking.booking_number}/cancel`, { method: 'POST', token });
      toast.success('Your booking has been cancelled.');
      await load();
    } catch (error) {
      toast.error(error instanceof ApiError
        ? Object.values(error.errors || {}).flat()[0] || error.message
        : 'That could not be cancelled.');
    } finally {
      setCancelling(false);
      setConfirmingCancel(false);
    }
  };

  const currentStep = STEPS.findIndex(([status]) => status === booking.status);
  const isCancelled = booking.status === 'cancelled';
  const updates = guestUpdates(booking.history, booking.status);

  return (
    <>
      <nav className="crumbs mb-3" aria-label="Breadcrumb">
        <Link href="/account/bookings">Your stays</Link>
        <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
        <span className="text-ink">{booking.booking_number}</span>
      </nav>

      {/*
        * A receipt for the payment just made, not a fixture of the booking.
        *
        * These ride on ?payment=success in the address bar, which never goes
        * away -- so a refreshed or bookmarked stay went on announcing a payment
        * that had cleared days earlier, still sitting at the top while the
        * guest was already in the room. They belong to the moment of return
        * from the provider, so they stop once the stay is under way.
        */}
      {showPaymentResult && paymentResult === 'success' && (
        <div className="alert alert-success">Your payment went through. Thank you.</div>
      )}
      {showPaymentResult && paymentResult === 'failed' && (
        <div className="alert alert-danger">That payment did not go through. Nothing has been charged.</div>
      )}
      {showPaymentResult && paymentResult === 'pending' && (
        <div className="alert alert-warning">
          Your payment is still being checked with the provider. This page will catch up on its own.
        </div>
      )}

      <div className="row g-4">
        <div className="col-lg-8">
          <div className="panel mb-4">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
              <div>
                <h1 className="h4 mb-1">{booking.room.name}</h1>
                <p className="text-soft small mb-0">
                  {booking.booking_number} · booked {formatDateTime(booking.placed_at)}
                </p>
              </div>

              {booking.room.slug && (
                <Link href={`/homestay/${booking.room.slug}`} className="btn btn-quiet btn-sm">
                  See the room
                </Link>
              )}
            </div>

            {/* A cancelled stay gets no timeline: four steps with a line through
                them say less than one sentence does. */}
            {isCancelled ? (
              <div className="alert alert-danger mb-0">
                This booking was cancelled
                {booking.cancelled_at ? ` on ${formatDate(booking.cancelled_at)}` : ''}.
                {booking.is_refundable && ' Anything you paid is refundable.'}
              </div>
            ) : (
              <>
                <ol className="stay-steps mb-0">
                  {STEPS.map(([status, label, icon], index) => (
                    <li
                      key={status}
                      className={`stay-steps__step ${index <= currentStep ? 'is-done' : ''} ${index === currentStep ? 'is-now' : ''}`}
                    >
                      <span className="stay-steps__dot"><i className={`bi ${icon}`} aria-hidden="true" /></span>
                      <span className="stay-steps__label">{label}</span>
                    </li>
                  ))}
                </ol>

                {/*
                  What "Booked" actually means, said on the step itself.

                  A green tick under the word Booked reads as finished, and the
                  guest has no way to know there is a step after it or what
                  moves them along. The timeline showed the shape of the journey
                  without ever saying whose turn it was.

                  Careful about what is promised here: the nights genuinely are
                  held from the moment the booking is placed -- the unique index
                  on (room_id, night) sees to that -- so this must not imply the
                  room might be sold from under them. What is missing is the
                  money, and that is what confirms it.
                */}
                {booking.status === 'placed' && (
                  <div className="alert alert-warning mt-3 mb-0">
                    <strong>Not confirmed yet.</strong>{' '}
                    These nights are held in your name, but the booking is only confirmed once we
                    have {booking.payment.awaiting_advance ? 'your advance' : 'your payment'} of{' '}
                    <strong>{formatMoney(booking.totals.due_now, settings)}</strong>.
                  </div>
                )}
              </>
            )}
          </div>

          <div className="panel mb-4">
            <h2 className="h6 mb-3">Your stay</h2>

            <dl className="spec-list mb-0">
              <div>
                <dt>Arrive</dt>
                <dd>{formatDate(booking.stay.check_in)}, from {booking.stay.check_in_time}</dd>
              </div>
              <div>
                <dt>Leave</dt>
                <dd>{formatDate(booking.stay.check_out)}, by {booking.stay.check_out_time}</dd>
              </div>
              <div>
                <dt>Nights</dt>
                <dd>{booking.stay.nights}</dd>
              </div>
              <div>
                <dt>Guests</dt>
                <dd>{booking.stay.guests}</dd>
              </div>
              <div>
                <dt>Booked for</dt>
                <dd>{booking.guest.name} · {booking.guest.phone}</dd>
              </div>
            </dl>
          </div>

          {booking.house_rules && (
            <div className="panel mb-4">
              <h2 className="h6 mb-2">House rules</h2>
              <p className="text-soft small mb-0" style={{ whiteSpace: 'pre-line' }}>
                {booking.house_rules}
              </p>
            </div>
          )}

          {updates.length > 0 && (
            <div className="panel">
              <h2 className="h6 mb-3">Updates</h2>
              <ul className="list-unstyled mb-0">
                {updates.map((entry) => (
                  <li key={`${entry.to}-${entry.at}`} className="d-flex gap-3 mb-2">
                    <span className="text-soft small flex-shrink-0" style={{ minWidth: '9rem' }}>
                      {formatDateTime(entry.at)}
                    </span>
                    {/*
                      The status, and nothing else.

                      The note is written for the farm, not for the guest --
                      the admin's own status form says it is kept "so it is
                      there when somebody asks later what happened". Printing
                      it here put machine-speak on a customer's receipt:
                      "Booked — Booking placed" says the same thing twice, and
                      "You are here — Checked in automatically — paid in full"
                      reads like a log line that escaped. The notes are still
                      on the booking in the admin, where they were promised to
                      be.
                    */}
                    <span className="small">
                      <strong className="text-ink">{GUEST_LABELS[entry.to] || entry.label}</strong>
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>

        <div className="col-lg-4">
          <div className="panel mb-4">
            <h2 className="h6 mb-3">What it costs</h2>

            <div className="stay-totals">
              <div>
                <span>
                  {formatMoney(booking.totals.rate_per_night, settings)} × {booking.stay.nights} night
                  {booking.stay.nights === 1 ? '' : 's'}
                </span>
                <span>{formatMoney(booking.totals.room_charge, settings)}</span>
              </div>

              {booking.totals.extra_guest_charge > 0 && (
                <div>
                  <span>Extra guests</span>
                  <span>{formatMoney(booking.totals.extra_guest_charge, settings)}</span>
                </div>
              )}

              {booking.totals.discount > 0 && (
                <div>
                  <span>Discount</span>
                  <span>−{formatMoney(booking.totals.discount, settings)}</span>
                </div>
              )}

              <div className="stay-totals__grand">
                <span>Total</span>
                <span>{formatMoney(booking.totals.total, settings)}</span>
              </div>

              <div>
                <span>Paid</span>
                <span>{formatMoney(booking.totals.paid, settings)}</span>
              </div>

              {booking.totals.balance_due > 0.009 && (
                <div>
                  <span>Outstanding</span>
                  <span>{formatMoney(booking.totals.balance_due, settings)}</span>
                </div>
              )}
            </div>

            {/*
              The plan is a statement about money still to come, so it stops
              being true the moment there is none. "Advance now, the rest on
              arrival" sitting under a settled bill reads as a bill that is not
              settled, which is the one thing this panel must never say.
            */}
            <p className="text-soft small mt-3 mb-0">
              {booking.payment.is_fully_paid
                ? 'Paid in full — nothing left to pay.'
                : booking.payment.plan_label}
            </p>
          </div>

          <div className="mb-4">
            <BookingPayment booking={booking} onDone={load} />
          </div>

          {booking.can_cancel && (
            <div className="panel">
              <h2 className="h6 mb-1">Change of plan?</h2>
              <p className="text-soft small mb-3">
                {booking.cancellation_note || 'Let us know as early as you can.'}
              </p>
              <button
                type="button"
                className="btn btn-quiet btn-sm text-danger w-100"
                onClick={() => setConfirmingCancel(true)}
                disabled={cancelling}
              >
                Cancel this booking
              </button>
            </div>
          )}
        </div>
      </div>

      <ConfirmDialog
        open={confirmingCancel}
        title="Cancel this booking?"
        lines={[
          'The room goes back on the calendar straight away, and somebody else can take it.',
          booking.is_refundable
            ? 'Anything you have paid becomes refundable.'
            : 'Nothing has been charged yet.',
        ]}
        confirmLabel="Yes, cancel it"
        busy={cancelling}
        onConfirm={cancel}
        onCancel={() => setConfirmingCancel(false)}
      />
    </>
  );
}
