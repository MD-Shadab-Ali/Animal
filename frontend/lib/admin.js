/**
 * Where the admin panel lives.
 *
 * Filament is served by Laravel, on a different origin to this app. A bare
 * "/admin" href would resolve against the storefront, which has no such route,
 * so the link has to be absolute and leave Next's router alone -- hence a plain
 * <a>, not <Link>, wherever this is used.
 */
export const ADMIN_URL = `${process.env.NEXT_PUBLIC_BACKEND_URL || 'http://127.0.0.1:8000'}/admin`;
