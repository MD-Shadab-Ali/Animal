import Link from 'next/link';

export const metadata = { title: 'Page not found' };

export default function NotFound() {
  return (
    <div className="section">
      <div className="container">
        <div className="empty">
          <i className="bi bi-signpost-2" />
          <h1 className="h3">We could not find that page</h1>
          <p>The goat may have been sold, or the link may be out of date.</p>
          <div className="d-flex gap-2 justify-content-center">
            <Link href="/" className="btn btn-brand px-4">Go home</Link>
            <Link href="/shop" className="btn btn-outline-brand px-4">Browse goats</Link>
          </div>
        </div>
      </div>
    </div>
  );
}
