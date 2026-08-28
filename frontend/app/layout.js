import { Rubik, Nunito_Sans } from 'next/font/google';
import Providers from './providers';
import Header from '@/components/layout/Header';
import Footer from '@/components/layout/Footer';
import AnnouncementBar from '@/components/layout/AnnouncementBar';
import ScrollToTop from '@/components/layout/ScrollToTop';
import { getSiteData, buildMetadata } from '@/lib/site';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import './globals.scss';

// Rubik for headings, Nunito Sans for body — the pairing the design system
// recommends for retail and product pages.
const heading = Rubik({
  subsets: ['latin'],
  weight: ['400', '500', '600', '700'],
  variable: '--font-heading',
  display: 'swap',
});

const body = Nunito_Sans({
  subsets: ['latin'],
  weight: ['300', '400', '500', '600', '700'],
  variable: '--font-body',
  display: 'swap',
});

export const viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: '#15803D',
};

export async function generateMetadata() {
  return buildMetadata();
}

export default async function RootLayout({ children }) {
  const site = await getSiteData();
  const { settings } = site;

  // The admin's colour choices become CSS variables the whole stylesheet reads.
  const theme = `:root{
    --brand-primary:${settings.primary_color || '#15803D'};
    --brand-secondary:${settings.secondary_color || '#22C55E'};
    --brand-accent:${settings.accent_color || '#A16207'};
  }`;

  return (
    <html lang="en" className={`${heading.variable} ${body.variable}`}>
      <head>
        <style dangerouslySetInnerHTML={{ __html: theme }} />
        {settings.site_favicon && <link rel="icon" href={settings.site_favicon} />}
        {settings.google_analytics_id && (
          <script async src={`https://www.googletagmanager.com/gtag/js?id=${settings.google_analytics_id}`} />
        )}
      </head>
      <body className="d-flex flex-column min-vh-100">
        <a href="#main" className="visually-hidden-focusable btn btn-brand m-2">Skip to content</a>

        <Providers site={site}>
          <AnnouncementBar settings={settings} />
          <Header site={site} />
          <main id="main" className="flex-grow-1">{children}</main>
          <Footer site={site} />
          <ScrollToTop />
        </Providers>
      </body>
    </html>
  );
}
