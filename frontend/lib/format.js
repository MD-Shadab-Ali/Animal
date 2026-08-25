/**
 * Money is formatted from the admin's currency settings, never hardcoded.
 * `settings` comes from /api/v1/site.
 */
export function formatMoney(amount, settings = {}) {
  const symbol = settings.currency_symbol ?? '';
  const position = settings.currency_position ?? 'before';

  // Digit grouping is admin-configurable: Nepal groups in lakhs (1,00,000),
  // which en-IN produces, while en-US would give 100,000.
  const locale = settings.number_locale || 'en-IN';

  const value = Number(amount || 0).toLocaleString(locale, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  });

  return position === 'after' ? `${value}${symbol}` : `${symbol}${value}`;
}

/** "1 yr 4 mo" reads better than "16 months" on a goat card. */
export function formatAge(months) {
  if (months === null || months === undefined) return null;
  if (months < 12) return `${months} mo`;

  const years = Math.floor(months / 12);
  const rest = months % 12;

  return rest ? `${years} yr ${rest} mo` : `${years} yr`;
}

export function formatDate(value) {
  if (!value) return '';
  return new Date(value).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}

export function formatDateTime(value) {
  if (!value) return '';
  return new Date(value).toLocaleString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}
