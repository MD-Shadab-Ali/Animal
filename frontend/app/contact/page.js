import ContactForm from '@/components/contact/ContactForm';
import FaqAccordion from '@/components/home/FaqAccordion';
import { apiFetch } from '@/lib/api';
import { buildMetadata, getSiteData } from '@/lib/site';

export async function generateMetadata() {
  return buildMetadata({
    title: 'Contact us',
    description: 'Call, message or write to us about any goat on the site.',
  });
}

export default async function ContactPage() {
  const [{ settings }, faqResponse] = await Promise.all([
    getSiteData(),
    apiFetch('/faqs?group=general', { revalidate: 300 }).catch(() => ({ data: [] })),
  ]);

  const faqs = faqResponse.data || [];

  const details = [
    ['bi-telephone', 'Phone', settings.contact_phone, `tel:${settings.contact_phone}`],
    ['bi-envelope', 'Email', settings.contact_email, `mailto:${settings.contact_email}`],
    ['bi-whatsapp', 'WhatsApp', settings.whatsapp_number, `https://wa.me/${settings.whatsapp_number}`],
    ['bi-geo-alt', 'Farm address', settings.contact_address, null],
    ['bi-clock', 'Opening hours', settings.business_hours, null],
  ].filter(([, , value]) => value);

  return (
    <>
      <div className="pagehead">
        <div className="container">
          <h1 className="section-title mb-1">Contact us</h1>
          <p className="section-sub mb-0">
            Questions about a particular goat, delivery or payment? Talk to us.
          </p>
        </div>
      </div>

      <div className="section">
        <div className="container">
          <div className="row g-4 g-lg-5">
            <div className="col-lg-5">
              <div className="panel h-100">
                <h2 className="h6 mb-4">Get in touch</h2>

                <ul className="list-unstyled d-grid gap-3 mb-0">
                  {details.map(([icon, label, value, href]) => (
                    <li className="d-flex gap-3" key={label}>
                      <span className="step__icon" style={{ width: 42, height: 42, fontSize: '1.1rem', margin: 0, borderRadius: 12 }}>
                        <i className={`bi ${icon}`} />
                      </span>
                      <span>
                        <span className="small text-soft d-block">{label}</span>
                        {href
                          ? <a href={href} className="fw-semibold text-body" target={href.startsWith('http') ? '_blank' : undefined} rel="noreferrer">{value}</a>
                          : <span className="fw-semibold">{value}</span>}
                      </span>
                    </li>
                  ))}
                </ul>

                {settings.google_map_embed && (
                  <div className="ratio ratio-4x3 mt-4 rounded overflow-hidden">
                    <iframe
                      src={settings.google_map_embed}
                      title="Our location"
                      loading="lazy"
                      referrerPolicy="no-referrer-when-downgrade"
                      style={{ border: 0 }}
                    />
                  </div>
                )}
              </div>
            </div>

            <div className="col-lg-7">
              <div className="panel">
                <h2 className="h6 mb-4">Send us a message</h2>
                <ContactForm />
              </div>
            </div>
          </div>

          {faqs.length > 0 && (
            <div className="mt-5 pt-4 border-top">
              <h2 className="section-title h4 text-center mb-4">Common questions</h2>
              <FaqAccordion items={faqs} id="contactFaq" />
            </div>
          )}
        </div>
      </div>
    </>
  );
}
