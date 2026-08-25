import Link from 'next/link';
import NewsletterForm from './NewsletterForm';

const SOCIALS = [
  ['facebook_url', 'bi-facebook', 'Facebook'],
  ['instagram_url', 'bi-instagram', 'Instagram'],
  ['youtube_url', 'bi-youtube', 'YouTube'],
  ['twitter_url', 'bi-twitter-x', 'X'],
  ['tiktok_url', 'bi-tiktok', 'TikTok'],
];

export default function Footer({ site }) {
  const { settings, menus, footer_pages: footerPages = [] } = site;

  const quickLinks = menus?.footer_quick_links || [];
  const supportLinks = menus?.footer_support?.length
    ? menus.footer_support
    : footerPages.map((page) => ({ label: page.title, url: page.url }));

  const socials = SOCIALS.filter(([key]) => settings[key] && settings[key] !== '#');

  return (
    <footer className="footer">
      <div className="container">
        <div className="row g-4 g-lg-5">
          <div className="col-lg-4">
            <div className="d-flex align-items-center gap-2 mb-3">
              {settings.footer_logo || settings.site_logo ? (
                <img
                  src={settings.footer_logo || settings.site_logo}
                  alt={settings.site_name}
                  style={{ maxHeight: 40 }}
                />
              ) : (
                <span className="brand text-white mb-0">
                  <span className="brand__mark" aria-hidden="true"><i className="bi bi-flower3" /></span>
                  {settings.site_name}
                </span>
              )}
            </div>

            {settings.footer_about && (
              <p className="mb-4" style={{ maxWidth: '38ch' }}>{settings.footer_about}</p>
            )}

            {socials.length > 0 && (
              <div className="d-flex gap-2">
                {socials.map(([key, icon, label]) => (
                  <a
                    key={key}
                    href={settings[key]}
                    className="social"
                    aria-label={label}
                    target="_blank"
                    rel="noreferrer"
                  >
                    <i className={`bi ${icon}`} />
                  </a>
                ))}
              </div>
            )}
          </div>

          {quickLinks.length > 0 && (
            <div className="col-6 col-lg-2">
              <h3>Shop</h3>
              <ul className="list-unstyled d-grid gap-2 mb-0">
                {quickLinks.map((item) => (
                  <li key={`${item.label}-${item.url}`}>
                    <Link href={item.url}>{item.label}</Link>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {supportLinks.length > 0 && (
            <div className="col-6 col-lg-2">
              <h3>Support</h3>
              <ul className="list-unstyled d-grid gap-2 mb-0">
                {supportLinks.map((item) => (
                  <li key={`${item.label}-${item.url}`}>
                    <Link href={item.url}>{item.label}</Link>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="col-lg-4">
            <h3>Get in touch</h3>
            <ul className="list-unstyled d-grid gap-2 mb-4">
              {settings.contact_phone && (
                <li className="d-flex gap-2">
                  <i className="bi bi-telephone" />
                  <a href={`tel:${settings.contact_phone}`}>{settings.contact_phone}</a>
                </li>
              )}
              {settings.contact_email && (
                <li className="d-flex gap-2">
                  <i className="bi bi-envelope" />
                  <a href={`mailto:${settings.contact_email}`}>{settings.contact_email}</a>
                </li>
              )}
              {settings.contact_address && (
                <li className="d-flex gap-2">
                  <i className="bi bi-geo-alt" />
                  <span>{settings.contact_address}</span>
                </li>
              )}
              {settings.business_hours && (
                <li className="d-flex gap-2">
                  <i className="bi bi-clock" />
                  <span>{settings.business_hours}</span>
                </li>
              )}
            </ul>

            <NewsletterForm />
          </div>
        </div>

        <div className="footer__bottom d-flex flex-wrap justify-content-between gap-2">
          <span>{settings.copyright_text}</span>
          {settings.whatsapp_number && (
            <a href={`https://wa.me/${settings.whatsapp_number}`} target="_blank" rel="noreferrer">
              <i className="bi bi-whatsapp me-1" /> Chat on WhatsApp
            </a>
          )}
        </div>
      </div>
    </footer>
  );
}
