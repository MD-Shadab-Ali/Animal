import Link from 'next/link';
import BorderGlow from '@/components/ui/BorderGlow';
import { formatDate } from '@/lib/format';

export default function PostGrid({ posts = [], columns = 3 }) {
  if (!posts.length) return null;

  return (
    <div className={`row row-cols-1 row-cols-md-${Math.min(columns, 3)} g-3 g-lg-4`}>
      {posts.map((post, index) => (
        <div className="col rise" key={post.slug} style={{ animationDelay: `${index * 45}ms` }}>
          {/*
            The glow wrapper is the card surface now, so the article inside
            gives up its own background and border -- see .card-goat--bare.
            Everything else about the card is untouched.
          */}
          <BorderGlow className="h-100">
            <article className="card-goat card-goat--bare h-100">
            <Link href={`/blog/${post.slug}`} className="card-goat__media d-block" aria-label={post.title}>
              {post.cover_image
                ? <img src={post.cover_image} alt="" loading="lazy" />
                : <div className="card-goat__empty"><i className="bi bi-journal-text" aria-hidden="true" /></div>}
            </Link>

            <div className="card-goat__body">
              <span className="card-goat__cat">
                {post.category?.name || 'Guide'}
                {post.published_at && <span className="text-soft ms-2 fw-normal text-lowercase">{formatDate(post.published_at)}</span>}
              </span>

              <Link href={`/blog/${post.slug}`} className="card-goat__name">{post.title}</Link>

              {post.excerpt && <p className="small text-soft mb-0">{post.excerpt}</p>}

              <span className="card-goat__foot small fw-semibold text-brand">
                Read guide <i className="bi bi-arrow-right" aria-hidden="true" />
              </span>
            </div>
            </article>
          </BorderGlow>
        </div>
      ))}
    </div>
  );
}
