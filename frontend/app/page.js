import SectionRenderer from '@/components/home/SectionRenderer';
import { apiFetch } from '@/lib/api';
import { buildMetadata } from '@/lib/site';

export async function generateMetadata() {
  return buildMetadata();
}

async function getSections() {
  try {
    const response = await apiFetch('/home', { revalidate: 60, tags: ['goats'] });
    return response.data || [];
  } catch (error) {
    console.error('Could not load homepage sections:', error.message);
    return [];
  }
}

export default async function HomePage() {
  const sections = await getSections();

  if (!sections.length) {
    return (
      <div className="container">
        <div className="empty">
          <i className="bi bi-layout-text-window" />
          <h1 className="h4">Nothing to show yet</h1>
          <p className="mb-0">
            Add homepage sections in the admin panel under <strong>Storefront → Homepage sections</strong>.
          </p>
        </div>
      </div>
    );
  }

  return (
    <>
      {sections.map((section, index) => (
        <SectionRenderer key={`${section.type}-${index}`} section={section} />
      ))}
    </>
  );
}
