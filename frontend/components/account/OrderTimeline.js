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

/*
 * The same journey, for an order the buyer is collecting.
 *
 * Nothing is dispatched and nothing arrives, so the last two steps cannot keep
 * their delivery names: "On the way" describes a van that does not exist, and
 * "Delivered" describes an event that never happens. The statuses underneath
 * are unchanged -- this is only what the buyer is told they mean.
 */
export const COLLECTION_STATUS_LABELS = {
  ...BUYER_STATUS_LABELS,
  // The buyer's own step: on a collection the farm cannot know when somebody
  // has set off, so this is theirs to set rather than staff's to guess.
  out_for_delivery: 'On the way to collect',
  delivered: 'Collected',
};

const COLLECTION_HEADLINES = {
  pending: 'Order placed',
  confirmed: 'Order confirmed',
  processing: 'Preparing your goat',
  out_for_delivery: 'On the way to collect',
  delivered: 'Collected',
};

const COLLECTION_ICONS = {
  out_for_delivery: 'bi-bag-check',
  delivered: 'bi-check-circle',
};

// The progression, in order. Cancelled is a status but not a step.
const STEPS = [
  ['pending', BUYER_STATUS_LABELS.pending, 'bi-bag-check'],
  ['confirmed', BUYER_STATUS_LABELS.confirmed, 'bi-patch-check'],
  ['processing', BUYER_STATUS_LABELS.processing, 'bi-box-seam'],
  ['out_for_delivery', BUYER_STATUS_LABELS.out_for_delivery, 'bi-truck'],
  ['delivered', BUYER_STATUS_LABELS.delivered, 'bi-house-check'],
];

/*
 * The headline. Every large storefront leads its order page with the state in
 * plain words rather than with the order number -- "where is my goat" is the
 * question the page is opened to answer, and a reference code answers a
 * different one.
 */
const HEADLINES = {
  pending: 'Order placed',
  confirmed: 'Order confirmed',
  processing: 'Preparing your goat',
  out_for_delivery: 'On the way to you',
  delivered: 'Delivered',
};

export default function OrderTimeline({
  status,
  /*
   * True when the buyer is coming to the farm rather than being delivered to.
   *
   * The order knows this and the tracker did not, so a collection order was
   * being told its goat was "On the way" and would be "Delivered" -- and the
   * estimate, which for a collection reads "Ready at the time you choose", was
   * being spliced into "Usually arrives within ... of dispatch."
   */
  collection = false,
  paid = 0,
  refunded = 0,
  formatAmount,
  // The delivery promise made when the zone was chosen, and — once it is
  // actually here — the day it turned up, which supersedes it.
  estimate = null,
  deliveredAt = null,
  formatWhen,
  // Every status change staff have recorded, which is where each step gets the
  // time it happened. A tracker that dates its steps is the difference between
  // "something is happening" and knowing when it did.
  history = [],
  placedAt = null,
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

  // Which vocabulary this order speaks. The steps themselves never change.
  const labels = collection ? COLLECTION_STATUS_LABELS : BUYER_STATUS_LABELS;
  const headlines = collection ? COLLECTION_HEADLINES : HEADLINES;

  const currentIndex = STEPS.findIndex(([key]) => key === status);

  /*
   * When each step happened. The first entry for a status is the one that
   * counts: an order sent back a step and moved on again reached "Preparing"
   * when it first got there, not on the retry.
   */
  const reachedAt = (key) => {
    const entry = (history || []).find((row) => row.status === key);

    if (entry?.created_at) return entry.created_at;
    if (key === 'pending') return placedAt;
    if (key === 'delivered') return deliveredAt;

    return null;
  };

  // "When does my goat get here" is the question this part of the page exists
  // to answer. An estimate that stops being shown the moment it starts to
  // matter is not much of a promise.
  const when = () => {
    if (status === 'delivered') {
      if (collection) {
        return deliveredAt && formatWhen
          ? `Collected on ${formatWhen(deliveredAt)}.`
          : 'Collected.';
      }

      return deliveredAt && formatWhen
        ? `Delivered on ${formatWhen(deliveredAt)}.`
        : 'Delivered.';
    }

    if (!estimate) return null;

    /*
     * A collection estimate is already a sentence about when the goat is ready
     * -- "Ready at the time you choose". Wrapping it in the delivery template
     * produced "Usually arrives within Ready at the time you choose of
     * dispatch", so it is shown as it was written.
     */
    if (collection) return estimate;

    return status === 'out_for_delivery'
      ? `On its way — usually arrives within ${estimate}.`
      : `Usually arrives within ${estimate} of dispatch.`;
  };

  const arrival = when();

  return (
    <>
      <div className="tracker__head">
        <span className={`tracker__badge ${status === 'delivered' ? 'is-done' : ''}`} aria-hidden="true">
          <i className={`bi ${STEPS[currentIndex]?.[2] || 'bi-bag-check'}`} />
        </span>

        <div className="min-w-0">
          <h2 className="tracker__title">{headlines[status] || labels[status] || status}</h2>

          {arrival && (
            <p className="tracker__lead">
              <i className={`bi ${status === 'delivered' ? 'bi-check-circle' : 'bi-truck'} me-1`} aria-hidden="true" />
              {arrival}
            </p>
          )}
        </div>
      </div>

      <ol className="tracker" aria-label="Order progress">
        {STEPS.map(([key, deliveryLabel, deliveryIcon], index) => {
          const label = labels[key] || deliveryLabel;
          const icon = (collection && COLLECTION_ICONS[key]) || deliveryIcon;

          const done = index <= currentIndex;
          const current = index === currentIndex;
          const at = reachedAt(key);

          return (
            <li
              className={`tracker__step ${done ? 'is-done' : ''} ${current ? 'is-current' : ''}`}
              key={key}
              aria-current={current ? 'step' : undefined}
            >
              <span className="tracker__dot" aria-hidden="true">
                <i className={`bi ${done && !current ? 'bi-check-lg' : icon}`} />
              </span>

              <span className="tracker__label">
                {label}
                <span className="visually-hidden">
                  {current ? ' — current step' : done ? ' — done' : ' — not yet'}
                </span>
              </span>

              {/* Dated from what staff actually recorded, so a step with no
                  entry behind it stays blank rather than inventing a time. */}
              <span className="tracker__when">
                {at && formatWhen ? formatWhen(at) : ''}
              </span>
            </li>
          );
        })}
      </ol>
    </>
  );
}
