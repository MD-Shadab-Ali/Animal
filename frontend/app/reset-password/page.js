import Link from 'next/link';

/**
 * The old link-based reset, kept only to catch stale emails.
 *
 * Reset is a code now: the address is proved by typing back six digits rather
 * than by following a link, so any link still sitting in an inbox points at a
 * flow that no longer exists. Better to say so than to 404.
 */
export default function ResetPasswordPage() {
  return (
    <div className="section">
      <div className="container" style={{ maxWidth: 460 }}>
        <div className="panel">
          <h1 className="h4 mb-1">This link is out of date</h1>
          <p className="text-soft small mb-4">
            We send a 6-digit code now instead of a link. Ask for one and you can choose a
            new password on the spot.
          </p>

          <Link href="/forgot-password" className="btn btn-brand w-100 mb-3">
            Send me a code
          </Link>

          <p className="text-center small mb-0">
            <Link href="/login" className="text-brand fw-semibold">Back to sign in</Link>
          </p>
        </div>
      </div>
    </div>
  );
}
