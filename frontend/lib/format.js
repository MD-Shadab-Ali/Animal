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

/**
 * How long ago, in the words people use out loud.
 *
 * A notification is read as "did this just happen?", and a full date makes the
 * reader do the arithmetic. Past a week the answer stops being a duration and
 * becomes a date again, which is why this hands over to formatDate rather than
 * counting weeks nobody can picture.
 */
export function formatSince(value) {
  if (!value) return '';

  const seconds = Math.floor((Date.now() - new Date(value).getTime()) / 1000);

  // Clocks drift, and a server can be a second ahead. "In 1 second" would be an
  // odd thing for a shop to tell somebody about their own order.
  if (seconds < 60) return 'Just now';

  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} min ago`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} hr ago`;

  const days = Math.floor(hours / 24);
  if (days === 1) return 'Yesterday';
  if (days < 7) return `${days} days ago`;

  return formatDate(value);
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
