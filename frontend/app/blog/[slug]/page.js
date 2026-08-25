import Link from 'next/link';
import { notFound } from 'next/navigation';
import { apiFetchOrNull } from '@/lib/api';
import { buildMetadata } from '@/lib/site';
import { formatDate } from '@/lib/format';

async function getPost(slug) {
  const response = await apiFetchOrNull(`/posts/${slug}`, { revalidate: 120 });
  return response?.data ?? null;
}

export async function generateMetadata({ params }) {
  const { slug } = await params;
  const post = await getPost(slug);

  if (!post) return buildMetadata({ title: 'Article not found' });

  return buildMetadata({
    title: post.seo?.title || post.title,
    description: post.seo?.description,
    image: post.cover_image,
  });
}

export default async function PostPage({ params }) {
  const { slug } = await params;
  const post = await getPost(slug);

  if (!post) notFound();

  return (
    <article>
      <div
        className="pagehead"
        style={post.cover_image ? {
          backgroundImage: `linear-gradient(rgba(20,28,24,.65), rgba(20,28,24,.65)), url(${post.cover_image})`,
          backgroundSize: 'cover',
          backgroundPosition: 'center',
          color: '#fff',
        } : undefined}
      >
        <div className="container">
          <nav aria-label="breadcrumb">
            <ol className="breadcrumb mb-2 small">
              <li className="breadcrumb-item"><Link href="/blog">Care guides</Link></li>
              {post.category?.name && (
                <li className="breadcrumb-item">
                  <Link href={`/blog?category=${post.category.slug}`}>{post.category.name}</Link>
                </li>
              )}
            </ol>
          </nav>

          <h1 className="section-title mb-2">{post.title}</h1>

          <div className="small">
            {post.author && <span>{post.author}</span>}
            {post.published_at && <span> · {formatDate(post.published_at)}</span>}
          </div>
        </div>
      </div>

      <div className="section">
        <div className="container">
          <div className="col-lg-8 mx-auto">
            {post.excerpt && <p className="lead text-soft">{post.excerpt}</p>}
            <div className="prose" dangerouslySetInnerHTML={{ __html: post.body || '' }} />

            <hr className="my-5" />

            <Link href="/blog" className="btn btn-outline-brand">
              <i className="bi bi-arrow-left me-1" />All care guides
            </Link>
          </div>
        </div>
      </div>
    </article>
  );
}
