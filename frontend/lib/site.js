import { cache } from 'react';
import { apiFetch } from './api';

/**
 * Site settings, navigation and footer pages — all admin-controlled.
 * Wrapped in React's `cache` so one render pass hits the API once.
 */
export const getSiteData = cache(async () => {
  try {
    const response = await apiFetch('/site', { revalidate: 60 });
    return response.data;
  } catch (error) {
    console.error('Could not load site settings:', error.message);
    return fallbackSite;
  }
});

/** Used only when the API is unreachable, so the shell still renders. */
const fallbackSite = {
  settings: {
    site_name: 'Goat Marketplace',
    site_tagline: '',
    currency_symbol: '',
    currency_position: 'before',
    primary_color: '#2f7a3e',
    secondary_color: '#8b5e34',
    accent_color: '#f0a92b',
    announcement_enabled: false,
    copyright_text: '',
  },
  menus: {},
  footer_pages: [],
};

/** Builds Next.js metadata from the admin's SEO settings. */
export async function buildMetadata({ title, description, image } = {}) {
  const { settings } = await getSiteData();
  const siteName = settings.site_name || 'Goat Marketplace';

  const pageTitle = title ? `${title} — ${siteName}` : settings.meta_title || siteName;
  const pageDescription = description || settings.meta_description || settings.site_tagline || '';
  const ogImage = image || settings.og_image || null;

  return {
    title: pageTitle,
    description: pageDescription,
    keywords: settings.meta_keywords || undefined,
    openGraph: {
      title: pageTitle,
      description: pageDescription,
      siteName,
      type: 'website',
      ...(ogImage ? { images: [{ url: ogImage }] } : {}),
    },
  };
}
