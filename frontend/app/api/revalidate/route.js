import { revalidateTag } from 'next/cache';

/**
 * Lets the admin panel tell the storefront that something has changed.
 *
 * Without this the shop finds out on its own schedule: a goat edited in
 * Filament went on showing its old weight for up to the page's revalidate
 * window, and the first visitor after that window still saw the stale copy
 * while Next rebuilt it behind them. Two refreshes to see one edit.
 *
 * Purges by tag rather than by path, because one goat appears on the home page,
 * the shop grid, its own page and the related strip of every sibling -- naming
 * those paths here would mean this file had to know the whole site map.
 */
export async function POST(request) {
  const expected = process.env.REVALIDATE_SECRET;

  // Unset means the feature is off rather than open to everyone.
  if (! expected) {
    return Response.json({ revalidated: false, reason: 'not configured' }, { status: 503 });
  }

  // Compared against a header rather than a query string, so the secret stays
  // out of access logs and the browser URL bar.
  if (request.headers.get('x-revalidate-secret') !== expected) {
    return Response.json({ revalidated: false, reason: 'bad secret' }, { status: 401 });
  }

  let tags = [];

  try {
    ({ tags = [] } = await request.json());
  } catch {
    return Response.json({ revalidated: false, reason: 'bad body' }, { status: 400 });
  }

  if (! Array.isArray(tags) || tags.length === 0) {
    return Response.json({ revalidated: false, reason: 'no tags' }, { status: 400 });
  }

  tags.forEach((tag) => revalidateTag(String(tag)));

  return Response.json({ revalidated: true, tags });
}
