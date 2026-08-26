/*
 * Keyed by the listing's state, not its approval.
 *
 * A sold goat keeps `approval_status = approved`, so keying off approval alone
 * left it advertised as "Live" after it had gone — the seller could not tell
 * what was still for sale.
 */
const MAP = {
  draft:    ['Draft', 'text-bg-secondary', 'bi-pencil'],
  pending:  ['Awaiting review', 'text-bg-warning', 'bi-hourglass-split'],
  live:     ['Live', 'text-bg-success', 'bi-check-circle'],
  rejected: ['Changes needed', 'text-bg-danger', 'bi-exclamation-triangle'],
  sold:     ['Sold', 'text-bg-dark', 'bi-bag-check'],
  hidden:   ['Not published', 'text-bg-secondary', 'bi-eye-slash'],
  archived: ['Archived', 'text-bg-secondary', 'bi-archive'],
};

export default function ListingStatusPill({ status }) {
  const [label, className, icon] = MAP[status] || ['Unknown', 'text-bg-secondary', 'bi-question'];

  return (
    <span className={`status-pill ${className}`}>
      <i className={`bi ${icon} me-1`} aria-hidden="true" />{label}
    </span>
  );
}
