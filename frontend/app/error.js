'use client';

import Link from 'next/link';
import { useEffect } from 'react';

export default function Error({ error, reset }) {
  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <div className="section">
      <div className="container">
        <div className="empty">
          <i className="bi bi-exclamation-triangle" />
          <h1 className="h3">Something went wrong</h1>
          <p>
            We could not load this page. If the problem continues, the API may be offline.
          </p>
          <div className="d-flex gap-2 justify-content-center">
            <button className="btn btn-brand px-4" onClick={reset}>Try again</button>
            <Link href="/" className="btn btn-outline-brand px-4">Go home</Link>
          </div>
        </div>
      </div>
    </div>
  );
}
