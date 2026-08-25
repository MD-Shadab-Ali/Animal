import Link from 'next/link';

export default function SectionHeader({ title, subtitle, align = 'start', action }) {
  if (!title && !subtitle) return null;

  if (align === 'center') {
    return (
      <div className="text-center mx-auto mb-4 mb-lg-5" style={{ maxWidth: '46rem' }}>
        {title && <h2 className="section-title">{title}</h2>}
        {subtitle && <p className="section-sub mx-auto">{subtitle}</p>}
      </div>
    );
  }

  return (
    <div className="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
      <div>
        {title && <h2 className="section-title">{title}</h2>}
        {subtitle && <p className="section-sub">{subtitle}</p>}
      </div>
      {action && (
        <Link href={action.href} className="btn btn-quiet btn-sm flex-shrink-0">
          {action.label} <i className="bi bi-arrow-right" aria-hidden="true" />
        </Link>
      )}
    </div>
  );
}
