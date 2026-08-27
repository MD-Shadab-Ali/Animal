'use client';

/**
 * What each status is called when a buyer reads it.
 *
 * Deliberately not the wording the API sends: `Order::STATUSES` is the
 * operational vocabulary staff work in ("Processing", "Out for delivery"),
 * and the buyer is told "Preparing" and "On the way". Exported so anything
 * else showing a status to a buyer says the same word this timeline does --
 * the update list underneath was reading the API label and saying
 * "Processing" directly below a step marked "Preparing".
 */
export const BUYER_STATUS_LABELS = {
  pending: 'Placed',
  confirmed: 'Confirmed',
  processing: 'Preparing',
  out_for_delivery: 'On the way',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
};

// The progression, in order. Cancelled is a status but not a step.
const STEPS = [
  ['pending', BUYER_STATUS_LABELS.pending],
  ['confirmed', BUYER_STATUS_LABELS.confirmed],
  ['processing', BUYER_STATUS_LABELS.processing],
  ['out_for_delivery', BUYER_STATUS_LABELS.out_for_delivery],
  ['delivered', BUYER_STATUS_LABELS.delivered],
];

export default function OrderTimeline({
  status,
  paid = 0,
  refunded = 0,
  formatAmount,
  // The delivery promise made when the zone was chosen, and — once it is
  // actually here — the day it turned up, which supersedes it.
  estimate = null,
  deliveredAt = null,
  formatWhen,
}) {
  if (status === 'cancelled') {
    // "Nothing has been charged" is only true when nothing was. Telling a buyer
    // who paid an advance that their money never left is the worst possible
    // thing this box could say.
    const money = (amount) => (formatAmount ? formatAmount(amount) : amount);

    return (
      <div className="alert alert-danger mb-0 d-flex align-items-start gap-2" style={{ borderRadius: 'var(--radius-md)' }}>
        <i className="bi bi-x-circle-fill mt-1" aria-hidden="true" />
        <span>
          This order was cancelled.{' '}
          {paid > 0
            ? `You paid ${money(paid)}, which is yours to have back.`
            : refunded > 0
              ? `The ${money(refunded)} you paid has been refunded.`
              : 'Nothing has been charged.'}
        </span>
      </div>
    );
  }

  const currentIndex = STEPS.findIndex(([key]) => key === status);

  // "When does my goat get here" is the question this part of the page exists
  // to answer. An estimate that stops being shown the moment it starts to
  // matter is not much of a promise.
  const when = () => {
    if (status === 'delivered') {
      return deliveredAt && formatWhen
        ? `Delivered on ${formatWhen(deliveredAt)}.`
        : 'Delivered.';
    }

    if (!estimate) return null;

    return status === 'out_for_delivery'
      ? `On its way — usually arrives within ${estimate}.`
      : `Usually arrives within ${estimate} of dispatch.`;
  };

  const arrival = when();

  return (
    <>
      <ol className="timeline list-unstyled mb-0">
        {STEPS.map(([key, label], index) => {
          const done = index <= currentIndex;

          return (
            <li className={`timeline__step ${done ? 'is-done' : ''}`} key={key}>
              <span className="timeline__dot" aria-hidden="true">
                <i className={`bi ${done ? 'bi-check-lg' : 'bi-circle'}`} />
              </span>
              <span>{label}</span>
            </li>
          );
        })}
      </ol>

      {arrival && (
        <p className="small text-soft mt-3 mb-0">
          <i className={`bi ${status === 'delivered' ? 'bi-check-circle' : 'bi-truck'} me-1`} aria-hidden="true" />
          {arrival}
        </p>
      )}
    </>
  );
}
