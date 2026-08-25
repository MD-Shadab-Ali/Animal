export default function StepList({ items = [] }) {
  if (!items.length) return null;

  return (
    <div className="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3">
      {items.map((item, index) => (
        <div className="col" key={index}>
          <div className="step h-100">
            <span className="step__icon"><i className={`bi bi-${item.icon || 'check-circle'}`} aria-hidden="true" /></span>
            <h3>{item.title}</h3>
            <p>{item.text}</p>
          </div>
        </div>
      ))}
    </div>
  );
}
