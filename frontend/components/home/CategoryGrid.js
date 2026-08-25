import Link from 'next/link';

export default function CategoryGrid({ categories = [], columns = 5 }) {
  if (!categories.length) return null;

  const columnClass = {
    3: 'row-cols-2 row-cols-lg-3',
    4: 'row-cols-2 row-cols-lg-4',
    5: 'row-cols-2 row-cols-md-3 row-cols-lg-5',
  }[columns] || 'row-cols-2 row-cols-md-3 row-cols-lg-5';

  return (
    <div className={`row g-3 ${columnClass}`}>
      {categories.map((category, index) => (
        <div className="col" key={category.slug}>
          <Link
            href={`/shop?category=${category.slug}`}
            className="tile-cat rise"
            style={{ animationDelay: `${Math.min(index, 8) * 45}ms` }}
          >
            <span className="tile-cat__icon">
              {category.image
                ? <img src={category.image} alt="" />
                : <i className={`bi ${category.icon || 'bi-tag'}`} aria-hidden="true" />}
            </span>
            <h3>{category.name}</h3>
            {category.goats_count !== undefined && <p>{category.goats_count} available</p>}
          </Link>
        </div>
      ))}
    </div>
  );
}
