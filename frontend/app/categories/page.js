import CategoryGrid from '@/components/home/CategoryGrid';
import { apiFetch } from '@/lib/api';
import { buildMetadata } from '@/lib/site';

export async function generateMetadata() {
  return buildMetadata({
    title: 'Categories',
    description: 'Browse goats by what you need them for.',
  });
}

export default async function CategoriesPage() {
  const response = await apiFetch('/categories', { revalidate: 300 });
  const categories = response.data || [];

  return (
    <>
      <div className="pagehead">
        <div className="container">
          <h1 className="section-title mb-1">Categories</h1>
          <p className="section-sub mb-0">Pick the right animal for what you need it for.</p>
        </div>
      </div>

      <div className="section">
        {/*
          Three across, each tile filling its column rather than capping at the
          width the home page uses -- there the grid is one block among many and
          the gaps hold the row together, here it is the whole page. The class
          lifts the cap and nothing else, so the icon, name and count stay
          centred and stacked exactly as they are.
        */}
        <div className="container cat-grid-wide">
          {categories.length ? (
            <CategoryGrid categories={categories} columns={3} />
          ) : (
            <div className="empty">
              <i className="bi bi-folder" />
              <p className="mb-0">No categories have been published yet.</p>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
