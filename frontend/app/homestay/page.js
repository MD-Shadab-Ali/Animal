import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import RoomCard from '@/components/room/RoomCard';
import StayFilters from '@/components/room/StayFilters';
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

  /*
   * Two dates only describe nights if the second one is after the first.
   *
   * The filter itself will not let you pick otherwise, but the URL is not the
   * filter: a shared link, a stale bookmark or a hand-edited query can carry
   * any pair at all. The server ignores a backwards range and answers with
   * every room, and the count below would then say those rooms were
   * "available on those nights" for a stay nobody could take. Say plainly how
   * many rooms there are instead, and keep the dates in the form so they can
   * be corrected. ISO dates compare correctly as strings.
   *
   * A range that has already been and gone fails for the same reason: last
   * January is in order and still not a stay you can book. `earliest_date` is
   * the same floor the date fields are given, so the two agree.
   */
  const hasNights = hasDates
    && dates.check_out > dates.check_in
    && (!homestay.earliest_date || dates.check_in >= homestay.earliest_date);

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
            Keyed on the current query so the fields follow the URL.

            The filters hold their own state once mounted, which is what makes
            typing feel immediate -- but it also means new props alone would not
            move them. Without this key, going Back after a search would restore
            the old results above a form still showing the abandoned dates. The
            key changes with the query, React remounts, and the two agree again.
          */}
          <StayFilters
            key={`${dates.check_in}|${dates.check_out}|${dates.guests}`}
            homestay={homestay}
            dates={dates}
          />

          <div className="toolbar">
            {/*
              "Available", never "free".

              On a page where every other number is money, "2 rooms free on
              those nights" reads first as two rooms at no charge. It means
              unoccupied, and it is the only word here that can be taken two
              ways -- so it goes.
            */}
            <p className="text-soft small mb-0">
              <strong className="text-ink">{total}</strong> room{total === 1 ? '' : 's'}
              {hasNights ? ' available on those nights' : ' available'}
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
                {hasNights ? 'No rooms available on those nights' : 'No rooms just now'}
              </h2>
              <p className="text-soft small mb-0">
                {hasNights
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
