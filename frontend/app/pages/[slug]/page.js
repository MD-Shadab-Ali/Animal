import { notFound } from 'next/navigation';
import { apiFetchOrNull } from '@/lib/api';
import { buildMetadata } from '@/lib/site';

async function getPage(slug) {
  const response = await apiFetchOrNull(`/pages/${slug}`, { revalidate: 60 });
  return response?.data ?? null;
}

export async function generateMetadata({ params }) {
  const { slug } = await params;
  const page = await getPage(slug);

  if (!page) return buildMetadata({ title: 'Page not found' });

  return buildMetadata({
    title: page.seo?.title || page.title,
    description: page.seo?.description,
  });
}

export default async function CmsPage({ params }) {
  const { slug } = await params;
  const page = await getPage(slug);

  if (!page) notFound();

  return (
    <>
      <div
        className="pagehead"
        style={page.banner ? {
          backgroundImage: `linear-gradient(rgba(20,28,24,.6), rgba(20,28,24,.6)), url(${page.banner})`,
          backgroundSize: 'cover',
          backgroundPosition: 'center',
          color: '#fff',
        } : undefined}
      >
        <div className="container">
          <h1 className="section-title mb-1">{page.title}</h1>
          {page.excerpt && <p className="section-sub mb-0">{page.excerpt}</p>}
        </div>
      </div>

      <div className="section">
        <div className="container">
          {/* Two columns only when there is a picture to put in the second one.
              A page without an image keeps its single reading column rather
              than sitting next to an empty half. */}
          <div className={page.side_image ? 'row g-4 g-lg-5' : ''}>
            <div className={page.side_image ? 'col-lg-7' : ''}>
              {/* Left edge shared with the title above, not centred against it. */}
              <div className="prose prose--page" dangerouslySetInnerHTML={{ __html: page.body || '' }} />
            </div>

            {page.side_image && (
              <div className="col-lg-5">
                <figure className="page-aside mb-0">
                  <img
                    src={page.side_image}
                    alt={page.side_image_caption || ''}
                    className="page-aside__img"
                    loading="lazy"
                  />
                  {page.side_image_caption && (
                    <figcaption className="page-aside__caption">
                      {page.side_image_caption}
                    </figcaption>
                  )}
                </figure>
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
