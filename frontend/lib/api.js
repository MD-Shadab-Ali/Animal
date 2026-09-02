const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8000/api/v1';

/**
 * Thin wrapper around fetch for the Laravel API.
 *
 * Server components call this during rendering; client components call it
 * with a token. Every failure surfaces as an ApiError carrying the status
 * and Laravel's validation errors, so forms can render field messages.
 */
export class ApiError extends Error {
  constructor(message, status, errors = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

export async function apiFetch(path, { method = 'GET', body, token, revalidate, cache, tags, signal } = {}) {
  // FormData carries file uploads. The browser must set its own Content-Type so
  // the multipart boundary is correct, so we deliberately leave the header off.
  const isUpload = typeof FormData !== 'undefined' && body instanceof FormData;

  const headers = { Accept: 'application/json' };

  if (body !== undefined && !isUpload) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;

  const options = {
    method,
    headers,
    signal,
    ...(body !== undefined ? { body: isUpload ? body : JSON.stringify(body) } : {}),
  };

  /*
   * Server-side rendering gets ISR; client-side calls always go to the network.
   *
   * `tags` name what a response is about, so the admin panel can purge it the
   * moment the data changes rather than leaving the storefront to notice on its
   * own up to `revalidate` seconds later. See app/api/revalidate/route.js.
   */
  if (typeof window === 'undefined') {
    options.next = { revalidate: revalidate ?? 60, ...(tags ? { tags } : {}) };
    if (cache) options.cache = cache;
  } else {
    options.cache = 'no-store';
  }

  const response = await fetch(`${API_URL}${path}`, options);

  if (response.status === 204) return null;

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok) {
    throw new ApiError(
      payload?.message || `Request failed with status ${response.status}`,
      response.status,
      payload?.errors || {}
    );
  }

  return payload;
}

/** Returns null instead of throwing on 404 — useful for optional content. */
export async function apiFetchOrNull(path, options) {
  try {
    return await apiFetch(path, options);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/**
 * Builds a FormData body, skipping empty values so optional file fields are
 * simply absent rather than sent as empty strings.
 */
export function toFormData(values) {
  const form = new FormData();

  Object.entries(values).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return;

    if (typeof File !== 'undefined' && value instanceof File) {
      form.append(key, value, value.name);
      return;
    }

    if (typeof value === 'boolean') {
      form.append(key, value ? '1' : '0');
      return;
    }

    // Several files under one name — `images[]` is what Laravel's `images.*`
    // rules expect. Without this an array lands as the string "[object File]".
    if (Array.isArray(value)) {
      value.forEach((entry) => {
        if (typeof File !== 'undefined' && entry instanceof File) {
          form.append(`${key}[]`, entry, entry.name);
        } else {
          form.append(`${key}[]`, entry);
        }
      });
      return;
    }

    form.append(key, value);
  });

  return form;
}
