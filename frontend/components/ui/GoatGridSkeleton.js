/** Reserves the same space the real grid will take, so nothing shifts (CLS). */
export default function GoatGridSkeleton({ count = 8, columns = 4 }) {
  const columnClass = {
    3: 'row-cols-2 row-cols-md-2 row-cols-lg-3',
    4: 'row-cols-2 row-cols-md-3 row-cols-xl-4',
  }[columns] || 'row-cols-2 row-cols-md-3 row-cols-xl-4';

  return (
    <div className={`row g-3 g-lg-4 ${columnClass}`} aria-hidden="true">
      {Array.from({ length: count }).map((_, index) => (
        <div className="col" key={index}>
          <div className="skeleton-card">
            <div className="skeleton skeleton-card__media" />
            <div className="p-3">
              <div className="skeleton skeleton-line" style={{ width: '40%' }} />
              <div className="skeleton skeleton-line" style={{ width: '85%' }} />
              <div className="skeleton skeleton-line" style={{ width: '60%' }} />
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}
