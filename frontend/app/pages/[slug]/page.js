import { notFound } from 'next/navigation';
import { apiFetchOrNull } from '@/lib/api';
import { buildMetadata } from '@/lib/site';

async function getPage(slug) {
  const response = await apiFetchOrNull(`/pages/${slug}`, { revalidate: 300 });
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
          <div className="col-lg-8 mx-auto">
            <div className="prose" dangerouslySetInnerHTML={{ __html: page.body || '' }} />
          </div>
        </div>
      </div>
    </>
  );
}
