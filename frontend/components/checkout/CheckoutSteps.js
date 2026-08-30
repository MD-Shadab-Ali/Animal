'use client';

export const CHECKOUT_STEPS = [
  { id: 1, label: 'Delivery', icon: 'bi-truck' },
  { id: 2, label: 'Payment', icon: 'bi-wallet2' },
  { id: 3, label: 'Review', icon: 'bi-check2-circle' },
];

/**
 * Where the buyer is in the checkout.
 *
 * A finished step stays clickable so an address can be corrected from the
 * review without losing the rest; steps ahead are not, because reaching one
 * has to go through the checks on the step before it.
 */
export default function CheckoutSteps({ current, onJump }) {
  return (
    <ol className="steps" aria-label="Checkout progress">
      {CHECKOUT_STEPS.map((step) => {
        const done = step.id < current;
        const isCurrent = step.id === current;

        return (
          <li
            key={step.id}
            className={`steps__item ${done ? 'is-done' : ''} ${isCurrent ? 'is-current' : ''}`}
          >
            <button
              type="button"
              className="steps__btn"
              onClick={() => onJump(step.id)}
              disabled={!done}
              aria-current={isCurrent ? 'step' : undefined}
            >
              <span className="steps__dot" aria-hidden="true">
                <i className={`bi ${done ? 'bi-check-lg' : step.icon}`} />
              </span>
              <span className="steps__label">
                {step.label}
                {done && <span className="visually-hidden"> (completed, go back to edit)</span>}
              </span>
            </button>
          </li>
        );
      })}
    </ol>
  );
}
