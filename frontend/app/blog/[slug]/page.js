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

/*
 * How long the guide takes to read, from its own markup.
 *
 * 200 words a minute is the usual desk-reading figure. Rounded up rather than
 * to nearest, so a guide that runs a shade over two minutes never advertises
 * itself as one.
 */
/*
 * Section anchors for the rail, and the ids they point at.
 *
 * The body is admin-editable HTML with no ids in it, so both are derived here
 * at render time rather than stored. An editor renaming a heading in Filament
 * should not have to think about anchors, and nothing written back to the
 * database means nothing for the rich editor to strip on the next save.
 */
function withSectionIds(html) {
  const sections = [];
  const used = new Set();

  const body = String(html || '').replace(/<h2([^>]*)>([\s\S]*?)<\/h2>/g, (match, attrs, inner) => {
    if (/\bid=/.test(attrs)) return match;

    const text = inner.replace(/<[^>]+>/g, '').trim();
    if (!text) return match;

    const base = text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'section';

    let id = base;
    for (let n = 2; used.has(id); n += 1) id = `${base}-${n}`;
    used.add(id);

    sections.push({ id, text });

    return `<h2${attrs} id="${id}">${inner}</h2>`;
  });

  return { body, sections };
}

/*
 * Gives every table the header row it is written with but cannot keep.
 *
 * The rich editor's table model has no thead and no scope: feed it a properly
 * built table and it hands back <table><tbody><tr><th>, with the attribute
 * dropped. So the markup in the database can never carry either, no matter how
 * it was authored -- which leaves a header row that is only visually a header,
 * and a screen reader with nothing to announce a column by.
 *
 * Restoring it here fixes every table on the site at once, including any an
 * admin adds later, and asks nothing of whoever writes them.
 */
function withTableHeaders(html) {
  return String(html || '').replace(/<table([^>]*)>([\s\S]*?)<\/table>/gi, (match, attrs, inner) => {
    if (/<thead[\s>]/i.test(inner)) return match;

    const firstRow = inner.match(/<tr[^>]*>[\s\S]*?<\/tr>/i);
    if (!firstRow) return match;

    // A header row is the first row, and only when every cell in it is a th.
    const row = firstRow[0];
    if (/<td[\s>]/i.test(row) || !/<th[\s>]/i.test(row)) return match;

    const head = row.replace(/<th\b(?![^>]*\bscope=)([^>]*)>/gi, '<th$1 scope="col">');

    // Lifted out of the body rather than wrapped in place: a thead nested
    // inside the tbody the editor emits would be invalid, and dropped.
    return `<table${attrs}><thead>${head}</thead>${inner.replace(row, '')}</table>`;
  });
}

function readingMinutes(html) {
  const words = String(html || '')
    .replace(/<[^>]+>/g, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean).length;

  return words ? Math.max(1, Math.ceil(words / 200)) : null;
}

export default async function PostPage({ params }) {
  const { slug } = await params;
  const post = await getPost(slug);

  if (!post) notFound();

  const minutes = readingMinutes(post.body);
  const { body, sections } = withSectionIds(withTableHeaders(post.body));

  return (
    <article>
      {/*
        The category is a chip above the title rather than a second breadcrumb:
        on a guide filed under Care guides the old trail read
        "Care guides / Care guides".
      */}
      <header
        className={`posthead${post.cover_image ? ' posthead--cover' : ''}`}
        style={post.cover_image ? { '--posthead-image': `url(${post.cover_image})` } : undefined}
      >
        <div className="container">
          <nav aria-label="breadcrumb">
            <ol className="breadcrumb posthead__crumbs small">
              <li className="breadcrumb-item"><Link href="/">Home</Link></li>
              <li className="breadcrumb-item"><Link href="/blog">Care guides</Link></li>
            </ol>
          </nav>

          {post.category?.name && (
            <Link href={`/blog?category=${post.category.slug}`} className="posthead__chip">
              {post.category.name}
            </Link>
          )}

          <h1 className="posthead__title">{post.title}</h1>

          {post.excerpt && <p className="posthead__deck">{post.excerpt}</p>}

          <div className="posthead__meta">
            {post.author && (
              <span><i className="bi bi-person-circle me-1" aria-hidden="true" />{post.author}</span>
            )}
            {post.published_at && (
              <>
                <span className="posthead__dot" aria-hidden="true" />
                <span><i className="bi bi-calendar3 me-1" aria-hidden="true" />{formatDate(post.published_at)}</span>
              </>
            )}
            {minutes && (
              <>
                <span className="posthead__dot" aria-hidden="true" />
                <span><i className="bi bi-clock me-1" aria-hidden="true" />{minutes} min read</span>
              </>
            )}
          </div>
        </div>
      </header>

      <div className="section">
        <div className="container">
          {/*
            Left-aligned against the hero rather than centred in the container.
            A centred col-lg-8 put the first word of the article 186px right of
            the title above it, and ran the lines out to 78 characters. See
            .article__body in globals.scss.

            The rail is what the left alignment buys: a guide runs to eight or
            ten sections, and the space beside a capped column is better spent
            on somewhere to jump to than on nothing. Below lg it would push the
            article down the page, so it stands down.
          */}
          <div className="row g-lg-5">
            <div className="col-lg-8">
              <div className="article__body">
                <div className="prose prose--article" dangerouslySetInnerHTML={{ __html: body }} />

                <hr className="my-5" />

                <Link href="/blog" className="btn btn-outline-brand">
                  <i className="bi bi-arrow-left me-1" />All care guides
                </Link>
              </div>
            </div>

            {sections.length >= 3 && (
              <aside className="col-lg-4 d-none d-lg-block">
                <nav className="article__toc" aria-labelledby="toc-heading">
                  <h2 className="article__toc-title" id="toc-heading">On this page</h2>
                  <ol>
                    {sections.map((section) => (
                      <li key={section.id}>
                        <a href={`#${section.id}`}>{section.text}</a>
                      </li>
                    ))}
                  </ol>
                </nav>
              </aside>
            )}
          </div>
        </div>
      </div>
    </article>
  );
}
