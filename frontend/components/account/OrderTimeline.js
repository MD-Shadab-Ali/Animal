'use client';

const STEPS = [
  ['pending', 'Placed'],
  ['confirmed', 'Confirmed'],
  ['processing', 'Preparing'],
  ['out_for_delivery', 'On the way'],
  ['delivered', 'Delivered'],
];

export default function OrderTimeline({ status }) {
  if (status === 'cancelled') {
    return (
      <div className="alert alert-danger mb-0 d-flex align-items-center gap-2" style={{ borderRadius: 'var(--radius-md)' }}>
        <i className="bi bi-x-circle-fill" aria-hidden="true" />
        This order was cancelled. Nothing has been charged.
      </div>
    );
  }

  const currentIndex = STEPS.findIndex(([key]) => key === status);

  return (
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
  );
}
