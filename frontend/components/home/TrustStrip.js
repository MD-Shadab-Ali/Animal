const ITEMS = [
  ['bi-patch-check', 'Vet-checked animals', 'Examined and certified before listing'],
  ['bi-speedometer2', 'Honest weights', 'Calibrated scale, photographed on request'],
  ['bi-truck', 'Careful delivery', 'Our own handlers, not a general courier'],
  ['bi-cash-coin', 'Cash on delivery', 'Inspect the goat first, then pay'],
];

/** Trust signals belong high on a marketplace page — buyers need them before price. */
export default function TrustStrip() {
  return (
    <div className="container">
      <div className="trust-strip">
        {ITEMS.map(([icon, title, text]) => (
          <div className="trust-item" key={title}>
            <span className="trust-item__icon"><i className={`bi ${icon}`} aria-hidden="true" /></span>
            <span>
              <strong>{title}</strong>
              <span>{text}</span>
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
