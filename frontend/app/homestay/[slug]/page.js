import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import BookBox from '@/components/room/BookBox';
import { apiFetchOrNull } from '@/lib/api';
import { buildMetadata } from '@/lib/site';

async function getRoom(slug) {
  const response = await apiFetchOrNull(`/rooms/${slug}`, { revalidate: 30, tags: ['rooms'] });
  return response?.data ?? null;
}

export async function generateMetadata({ params }) {
  const { slug } = await params;
  const room = await getRoom(slug);

  if (!room) return buildMetadata({ title: 'Room not found' });

  return buildMetadata({
    title: room.seo?.title || room.name,
    description: room.seo?.description,
    image: room.thumbnail,
  });
}

/**
 * One room, and the calendar for it.
 *
 * Revalidated on the short window the shop grid uses rather than the long one a
 * content page gets, and purged by tag the moment a booking is taken. A stale
 * price is embarrassing; a stale calendar offers a room somebody else has.
 */
export default async function RoomPage({ params }) {
  const { slug } = await params;
  const room = await getRoom(slug);

  if (!room || room.homestay?.enabled === false) notFound();

  const gallery = room.gallery || [];
  const sleeps = room.sleeps || {};
  const amenities = room.amenities || [];

  return (
    <article>
      <div className="pagehead">
        <div className="container">
          <nav className="crumbs mb-2" aria-label="Breadcrumb">
            <Link href="/">Home</Link>
            <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
            <Link href="/homestay">Homestay</Link>
            <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
            <span className="text-ink">{room.name}</span>
          </nav>

          <h1 className="section-title mb-2">{room.name}</h1>

          <div className="d-flex flex-wrap align-items-center gap-3 small text-soft">
            {room.room_type && (
              <span><i className="bi bi-house-door text-brand me-1" aria-hidden="true" />{room.room_type}</span>
            )}
            <span>
              <i className="bi bi-people text-brand me-1" aria-hidden="true" />
              Sleeps {sleeps.max}
              {sleeps.included < sleeps.max && ` (rate covers ${sleeps.included})`}
            </span>
            <span>
              <i className="bi bi-moon-stars text-brand me-1" aria-hidden="true" />
              {sleeps.beds} bed{sleeps.beds === 1 ? '' : 's'}
            </span>
            {sleeps.private_bathroom && (
              <span><i className="bi bi-droplet text-brand me-1" aria-hidden="true" />Private bathroom</span>
            )}
          </div>
        </div>
      </div>

      <div className="section">
        <div className="container">
          <div className="row g-4">
            <div className="col-lg-7">
              {/*
                A plain grid rather than a carousel. Every photograph is on the
                page at once, which is what somebody deciding where to sleep
                actually wants -- and it needs no JavaScript to show the second
                picture.
              */}
              {gallery.length > 0 ? (
                <div className="room-gallery">
                  {gallery.map((photo, index) => (
                    <img
                      key={photo.id}
                      src={photo.url}
                      alt={photo.alt || room.name}
                      className={index === 0 ? 'room-gallery__lead' : undefined}
                      loading={index === 0 ? undefined : 'lazy'}
                    />
                  ))}
                </div>
              ) : (
                <div className="room-gallery__empty">
                  <i className="bi bi-house-door" aria-hidden="true" />
                </div>
              )}

              {room.short_description && (
                <p className="lead mt-4 mb-0">{room.short_description}</p>
              )}

              {/* Written in the admin's rich editor, so it arrives as markup
                  the panel produced rather than as anything a guest typed. */}
              {room.description && (
                <div
                  className="prose prose--article mt-3"
                  dangerouslySetInnerHTML={{ __html: room.description }}
                />
              )}

              {amenities.length > 0 && (
                <div className="panel mt-4">
                  <h2 className="h6 mb-3">What the room has</h2>
                  <dl className="spec-list mb-0">
                    {amenities.map((item) => (
                      <div key={`${item.label}-${item.value}`}>
                        <dt>{item.label}</dt>
                        <dd>{item.value}</dd>
                      </div>
                    ))}
                  </dl>
                </div>
              )}

              {room.homestay?.house_rules && (
                <div className="panel mt-4">
                  <h2 className="h6 mb-2">House rules</h2>
                  <p className="text-soft small mb-0" style={{ whiteSpace: 'pre-line' }}>
                    {room.homestay.house_rules}
                  </p>
                </div>
              )}
            </div>

            <div className="col-lg-5">
              {/*
                Suspense because the box reads the query string for the dates
                the listing page was already asking about, and useSearchParams
                needs a boundary to render inside.
              */}
              <Suspense fallback={<div className="buybox buybox--sticky"><div className="skeleton skeleton-line" /></div>}>
                <BookBox room={room} />
              </Suspense>
            </div>
          </div>
        </div>
      </div>
    </article>
  );
}
