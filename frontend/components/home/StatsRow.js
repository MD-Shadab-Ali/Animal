export default function StatsRow({ items = [] }) {
  if (!items.length) return null;

  return (
    <div className="row row-cols-2 row-cols-lg-4 g-4">
      {items.map((item, index) => (
        <div className="col" key={index}>
          <div className="stat-block">
            <b>{item.value}</b>
            <span>{item.label}</span>
          </div>
        </div>
      ))}
    </div>
  );
}
