import Link from 'next/link';
import GoatGrid from '@/components/goat/GoatGrid';
import CategoryGrid from './CategoryGrid';
import FaqAccordion from './FaqAccordion';
import Hero from './Hero';
import PostGrid from './PostGrid';
import SectionHeader from './SectionHeader';
import StepList from './StepList';
import Testimonials from './Testimonials';

/**
 * The homepage is a list of admin-defined sections. This picks the right block
 * for each `type` and hands it the payload the API already resolved. Reordering
 * or hiding a section in Filament changes the page with no deploy; only a brand
 * new *kind* of section needs a case added here.
 */
export default function SectionRenderer({ section }) {
  const {
    type, title, subtitle, config = {}, data,
    background_color: background, custom_html: customHtml,
  } = section;

  /*
   * Full-bleed blocks render outside the standard section wrapper.
   *
   * A trust strip used to sit here, directly under the hero, saying the same
   * four things as the "Why buy from us" section further down -- while the hero
   * itself already carries a band with two of them. Three statements of one
   * promise inside a screen and a half does not build confidence; it reads as a
   * page with nothing else to say.
   *
   * What is left divides the work properly: the hero's own band is the glance,
   * and "Why buy from us" is the explanation. That one is a HomeSection, so the
   * farm can reword it without a deploy -- which the hardcoded strip never
   * allowed.
   */
  if (type === 'hero_slider') {
    return <Hero banners={data || []} />;
  }

  if (type === 'promo_banner') {
    const banner = (data || [])[0];
    if (!banner) return null;

    return (
      <section className="section-tight">
        <div className="container">
          <div
            className="cta-band d-flex flex-wrap align-items-center justify-content-between gap-3"
            style={banner.image ? {
              backgroundImage: `linear-gradient(100deg, rgba(20,83,45,.88), rgba(20,83,45,.45)), url(${banner.image})`,
              backgroundSize: 'cover',
              backgroundPosition: 'center',
            } : undefined}
          >
            <div>
              {banner.subtitle && <span className="eyebrow text-white-50 d-block mb-2">{banner.subtitle}</span>}
              {banner.title && <h2 className="h3 mb-2">{banner.title}</h2>}
              {banner.description && <p style={{ maxWidth: '54ch' }}>{banner.description}</p>}
            </div>
            {banner.button_text && banner.button_link && (
              <Link href={banner.button_link} className="btn btn-cta btn-lg flex-shrink-0">
                {banner.button_text}
              </Link>
            )}
          </div>
        </div>
      </section>
    );
  }

  if (type === 'cta') {
    return (
      <section className="section">
        <div className="container">
          <div className="cta-band text-center">
            {title && <h2 className="mb-2">{title}</h2>}
            {subtitle && <p className="mb-4 mx-auto" style={{ maxWidth: '54ch' }}>{subtitle}</p>}
            {config.button_text && config.button_link && (
              <Link href={config.button_link} className="btn btn-cta btn-lg">
                {config.button_text}
              </Link>
            )}
          </div>
        </div>
      </section>
    );
  }

  const body = renderBody(type, { data, config, customHtml });
  if (!body) return null;

  const centered = ['why_choose_us', 'testimonials', 'faq'].includes(type);
  const shopLink = ['featured_goats', 'latest_goats'].includes(type);

  return (
    <section
      className={`section ${background ? '' : (centered ? 'bg-surface-alt' : '')}`}
      style={background ? { backgroundColor: background } : undefined}
    >
      <div className="container">
        <SectionHeader
          title={title}
          subtitle={subtitle}
          align={centered ? 'center' : 'start'}
          action={shopLink ? { href: '/shop', label: 'View all' } : undefined}
        />
        {body}
      </div>
    </section>
  );
}

function renderBody(type, { data, config, customHtml }) {
  switch (type) {
    case 'categories':
      return <CategoryGrid categories={data || []} columns={config.columns || 5} />;

    case 'featured_goats':
    case 'latest_goats':
      return <GoatGrid goats={data || []} columns={config.columns || 4} fillRows />;

    case 'why_choose_us':
      return <StepList items={config.items || []} />;

    case 'testimonials':
      return <Testimonials items={data || []} />;

    case 'faq':
      return <FaqAccordion items={data || []} id="homeFaq" />;

    case 'blog':
      return <PostGrid posts={data || []} columns={config.columns || 3} />;

    case 'custom_html':
      return customHtml
        ? <div className="prose" dangerouslySetInnerHTML={{ __html: customHtml }} />
        : null;

    default:
      return null;
  }
}
