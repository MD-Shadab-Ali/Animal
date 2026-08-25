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
        <div className="container">
          {categories.length ? (
            <CategoryGrid categories={categories} columns={4} />
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
