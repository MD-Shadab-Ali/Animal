import Link from 'next/link';
import PostGrid from '@/components/home/PostGrid';
import { apiFetch } from '@/lib/api';
import { buildMetadata } from '@/lib/site';

export async function generateMetadata() {
  return buildMetadata({
    title: 'Care guides',
    description: 'Practical advice on housing, feeding and choosing a healthy goat.',
  });
}

export default async function BlogPage({ searchParams }) {
  const params = await searchParams;

  const query = new URLSearchParams();
  if (params?.category) query.set('category', params.category);
  if (params?.page) query.set('page', params.page);

  const [postsResponse, categoriesResponse] = await Promise.all([
    apiFetch(`/posts?${query.toString()}`, { revalidate: 120 }),
    apiFetch('/post-categories', { revalidate: 300 }),
  ]);

  const posts = postsResponse.data || [];
  const categories = categoriesResponse.data || [];

  return (
    <>
      <div className="pagehead">
        <div className="container">
          <h1 className="section-title mb-1">Care guides</h1>
          <p className="section-sub mb-0">
            How to house, feed and pick a healthy goat — written by the people who raise ours.
          </p>
        </div>
      </div>

      <div className="section">
        <div className="container">
          {categories.length > 0 && (
            <div className="d-flex flex-wrap gap-2 mb-4">
              <Link
                href="/blog"
                className={`btn btn-sm ${!params?.category ? 'btn-brand' : 'btn-outline-secondary'}`}
              >
                All
              </Link>
              {categories.map((category) => (
                <Link
                  key={category.slug}
                  href={`/blog?category=${category.slug}`}
                  className={`btn btn-sm ${params?.category === category.slug ? 'btn-brand' : 'btn-outline-secondary'}`}
                >
                  {category.name}
                  <span className="ms-1 opacity-75">({category.posts_count})</span>
                </Link>
              ))}
            </div>
          )}

          {posts.length ? (
            <PostGrid posts={posts} columns={3} />
          ) : (
            <div className="empty">
              <i className="bi bi-journal-text" />
              <p className="mb-0">No articles published yet.</p>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
