import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import RoomCard from '@/components/room/RoomCard';
import GoatGridSkeleton from '@/components/ui/GoatGridSkeleton';
import Pagination from '@/components/ui/Pagination';
import { apiFetch } from '@/lib/api';
import { buildMetadata } from '@/lib/site';

export async function generateMetadata() {
  return buildMetadata({
    title: 'Stay at the farm',
    description: 'Book a room at the farm — simple rooms, a hot meal, and the animals a short walk away.',
  });
}

const FILTER_KEYS = ['check_in', 'check_out', 'guests', 'max_price', 'ensuite', 'sort', 'page'];

export default async function HomestayPage({ searchParams }) {
  const params = await searchParams;

  const query = new URLSearchParams();
  FILTER_KEYS.forEach((key) => {
    if (params?.[key]) query.set(key, params[key]);
  });

  const [roomsResponse, optionsResponse] = await Promise.all([
    apiFetch(`/rooms?${query.toString()}`, { revalidate: 30, tags: ['rooms'] }),
    apiFetch('/rooms/options', { revalidate: 300, tags: ['rooms'] }),
  ]);

  const rooms = roomsResponse.data || [];
  const meta = roomsResponse.meta;
  const options = optionsResponse.data || {};
  const homestay = options.homestay || {};

  // The farm can switch the whole thing off, and a page that went on taking
  // bookings after they did would be promising beds nobody is making up.
  if (homestay.enabled === false) notFound();

  const total = meta?.total ?? rooms.length;

  // What the guest is already asking about, carried onto each card so the room
  // page opens with their dates rather than an empty calendar.
  const dates = {
    check_in: params?.check_in || '',
    check_out: params?.check_out || '',
    guests: params?.guests || '',
  };

  const hasDates = Boolean(dates.check_in && dates.check_out);

  return (
    <>
      <div className="pagehead">
        <div className="container">
          <nav className="crumbs mb-2" aria-label="Breadcrumb">
            <Link href="/">Home</Link>
            <i className="bi bi-chevron-right" style={{ fontSize: '.65rem' }} aria-hidden="true" />
            <span className="text-ink">Homestay</span>
          </nav>

          <h1 className="section-title mb-1">Stay at the farm</h1>
          {homestay.intro && <p className="section-sub">{homestay.intro}</p>}
        </div>
      </div>

      <div className="section">
        <div className="container">
          {/*
            A plain GET form rather than a client component.

            It is three fields and a button, and written this way it works
            before any JavaScript has loaded, keeps the chosen dates in the URL
            where they can be shared and bookmarked, and has no state to go
            wrong. The shop's filter rail earns its interactivity; this does not.
          */}
          <form method="get" className="stay-filters mb-4" aria-label="Find a room">
            <div>
              <label className="form-label small" htmlFor="filter-check-in">Arrive</label>
              <input
                id="filter-check-in"
                type="date"
                name="check_in"
                className="form-control"
                defaultValue={dates.check_in}
                min={homestay.earliest_date}
                max={homestay.latest_date}
              />
            </div>

            <div>
              <label className="form-label small" htmlFor="filter-check-out">Leave</label>
              <input
                id="filter-check-out"
                type="date"
                name="check_out"
                className="form-control"
                defaultValue={dates.check_out}
                min={homestay.earliest_date}
                max={homestay.latest_date}
              />
            </div>

            <div>
              <label className="form-label small" htmlFor="filter-guests">Guests</label>
              <input
                id="filter-guests"
                type="number"
                name="guests"
                className="form-control"
                min="1"
                max="20"
                defaultValue={dates.guests}
                placeholder="Any"
              />
            </div>

            <div className="stay-filters__go">
              <button type="submit" className="btn btn-brand w-100">
                <i className="bi bi-search me-1" aria-hidden="true" />Find a room
              </button>
            </div>
          </form>

          <div className="toolbar">
            <p className="text-soft small mb-0">
              <strong className="text-ink">{total}</strong> room{total === 1 ? '' : 's'}
              {hasDates ? ' free on those nights' : ' available'}
            </p>

            {hasDates && (
              <Link href="/homestay" className="btn btn-quiet btn-sm">
                <i className="bi bi-x-lg me-1" aria-hidden="true" />Clear dates
              </Link>
            )}
          </div>

          {rooms.length === 0 ? (
            <div className="panel text-center py-5">
              <i className="bi bi-calendar-x d-block mb-2" style={{ fontSize: '2rem' }} aria-hidden="true" />
              <h2 className="h5 mb-1">
                {hasDates ? 'Nothing free on those nights' : 'No rooms just now'}
              </h2>
              <p className="text-soft small mb-0">
                {hasDates
                  ? 'Try a different date, or a shorter stay.'
                  : 'Please check back, or call us to ask.'}
              </p>
            </div>
          ) : (
            <Suspense fallback={<GoatGridSkeleton count={6} columns={3} />}>
              <div className="row g-4">
                {rooms.map((room, index) => (
                  <div className="col-sm-6 col-lg-4" key={room.slug}>
                    <RoomCard room={room} index={index} dates={dates} />
                  </div>
                ))}
              </div>
            </Suspense>
          )}

          <Suspense fallback={null}>
            <Pagination meta={meta} basePath="/homestay" />
          </Suspense>

          {homestay.house_rules && (
            <div className="panel mt-4">
              <h2 className="h6 mb-2">House rules</h2>
              <p className="text-soft small mb-0" style={{ whiteSpace: 'pre-line' }}>
                {homestay.house_rules}
              </p>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
