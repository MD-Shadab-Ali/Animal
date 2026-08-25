const MAP = {
  draft:    ['Draft', 'text-bg-secondary', 'bi-pencil'],
  pending:  ['Awaiting review', 'text-bg-warning', 'bi-hourglass-split'],
  approved: ['Live', 'text-bg-success', 'bi-check-circle'],
  rejected: ['Changes needed', 'text-bg-danger', 'bi-exclamation-triangle'],
};

export default function ListingStatusPill({ status }) {
  const [label, className, icon] = MAP[status] || ['Unknown', 'text-bg-secondary', 'bi-question'];

  return (
    <span className={`status-pill ${className}`}>
      <i className={`bi ${icon} me-1`} aria-hidden="true" />{label}
    </span>
  );
}
