import Link from 'next/link';
import SpotlightGrid from '@/components/ui/SpotlightGrid';

export default function CategoryGrid({ categories = [], columns = 5 }) {
  if (!categories.length) return null;

  const columnClass = {
    3: 'row-cols-2 row-cols-lg-3',
    4: 'row-cols-2 row-cols-lg-4',
    5: 'row-cols-2 row-cols-md-3 row-cols-lg-5',
  }[columns] || 'row-cols-2 row-cols-md-3 row-cols-lg-5';

  /*
   * The grid stays a server component. SpotlightGrid is a client wrapper that
   * finds these tiles by their data attribute and drives the glow from the
   * pointer, so none of the markup below has to ship its own JavaScript.
   */
  return (
    <SpotlightGrid className={`row g-3 ${columnClass}`}>
      {categories.map((category, index) => (
        <div className="col" key={category.slug}>
          <Link
            href={`/shop?category=${category.slug}`}
            className="tile-cat is-spotlit rise"
            data-spotlight-card=""
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
    </SpotlightGrid>
  );
}
