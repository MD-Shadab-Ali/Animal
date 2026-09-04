'use client';

import Link from 'next/link';
import { useSettings } from '@/context/SiteContext';
import { formatMoney } from '@/lib/format';

/**
 * A room, as a full-bleed tile.
 *
 * Built on the same .goat-tile shape the shop grid uses, so the two listings
 * read as one site rather than as two products that happen to share a header.
 * What differs is what a card has to say: a goat card answers "what is it and
 * can I buy it", and a room card answers "will we fit, and what does a night
 * cost".
 *
 * There is no add-to-cart. A room cannot be bought without dates, and choosing
 * them on somebody's behalf is not a shortcut -- it is a decision they did not
 * make. So the action goes to the room's own page, where the calendar is.
 */
export default function RoomCard({ room, index = 0, dates }) {
  const settings = useSettings();

  const sleeps = room.sleeps || {};
  const pricing = room.pricing || {};

  // Carry the dates the guest was already asking about through to the room
  // page, so they do not have to pick them a second time.
  const query = dates?.check_in && dates?.check_out
    ? `?check_in=${dates.check_in}&check_out=${dates.check_out}${dates.guests ? `&guests=${dates.guests}` : ''}`
    : '';

  return (
    <article className="goat-tile rise" style={{ animationDelay: `${Math.min(index, 8) * 45}ms` }}>
      <div className="goat-tile__media">
        {room.thumbnail
          ? <img src={room.thumbnail} alt={room.name} loading="lazy" />
          : <div className="goat-tile__empty"><i className="bi bi-house-door" aria-hidden="true" /></div>}
      </div>

      <div className="goat-tile__wash" />

      <div className="goat-tile__tags">
        <span className="badge-verified" title={`Sleeps ${sleeps.max}`}>
          <i className="bi bi-people-fill" aria-hidden="true" />
          <span className="badge-verified__label">Sleeps {sleeps.max}</span>
        </span>
        {sleeps.private_bathroom && (
          <span className="badge-verified" title="Private bathroom">
            <i className="bi bi-droplet-fill" aria-hidden="true" />
            <span className="badge-verified__label">Ensuite</span>
          </span>
        )}
      </div>

      <div className="goat-tile__body">
        {room.room_type && <span className="goat-tile__cat">{room.room_type}</span>}

        <Link href={`/homestay/${room.slug}${query}`} className="goat-tile__name">{room.name}</Link>

        <span className="goat-tile__seller">
          <i className="bi bi-cash-coin" aria-hidden="true" />
          {formatMoney(pricing.per_night, settings)} a night
          {/* Said plainly on the card, because the maximum on its own promises
              four beds at a price that only buys two. */}
          {sleeps.included < sleeps.max && ` · covers ${sleeps.included}`}
        </span>

        <Link href={`/homestay/${room.slug}${query}`} className="goat-tile__action goat-tile__action--beam">
          <span>Check dates</span>
          <i className="bi bi-calendar-check" aria-hidden="true" />
        </Link>
      </div>
    </article>
  );
}
