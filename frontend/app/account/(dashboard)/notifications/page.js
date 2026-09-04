'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useCallback, useState } from 'react';
import Pagination from '@/components/ui/Pagination';
import { useAuth } from '@/context/AuthContext';
import { apiFetch } from '@/lib/api';
import { formatSince } from '@/lib/format';
import { useLiveRefresh } from '@/lib/useLiveRefresh';

// The same icons the bell uses. Kept in step deliberately: a notification that
// changes shape between the panel and this page reads as a different event.
const ICONS = {
  order: 'bi-box-seam',
  payment: 'bi-cash-coin',
  refund: 'bi-arrow-counterclockwise',
  booking: 'bi-house-door',
  general: 'bi-bell',
};

/**
 * Everything the shop has told this account.
 *
 * The bell holds six and is for glancing at; this is the record. Rows keep
 * their unread tint here too, because somebody arriving from "See all" is
 * usually looking for the one they have not read yet.
 */
export default function NotificationsPage() {
  const { token } = useAuth();
  const searchParams = useSearchParams();
  const page = searchParams.get('page') || '1';

  const [payload, setPayload] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    if (!token) return;

    try {
      setPayload(await apiFetch(`/notifications?page=${page}`, { token }));
    } catch {
      setPayload({ data: [] });
    }
  }, [token, page]);

  useLiveRefresh(load, { immediate: true, enabled: Boolean(token) });

  const readAll = async () => {
    setBusy(true);

    try {
      await apiFetch('/notifications/read-all', { method: 'POST', token });
      await load();
    } catch {
      // Nothing useful to say: the rows are readable either way.
    } finally {
      setBusy(false);
    }
  };

  if (payload === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  const rows = payload.data || [];
  const unread = payload.meta?.unread || 0;

  return (
    <div className="d-grid gap-4">
      <div className="panel">
        <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div>
            <h1 className="h5 mb-1">Notifications</h1>
            <p className="text-soft small mb-0">
              {unread > 0
                ? `${unread} you have not read yet.`
                : 'Everything here has been read.'}
            </p>
          </div>

          {unread > 0 && (
            <button type="button" className="btn btn-quiet btn-sm" onClick={readAll} disabled={busy}>
              {busy ? 'Marking…' : 'Mark all read'}
            </button>
          )}
        </div>

        {rows.length === 0 ? (
          <div className="empty">
            <i className="bi bi-bell-slash" />
            <p className="mb-0">
              Nothing yet. Order a goat or book a room and we will keep you posted here.
            </p>
          </div>
        ) : (
          <ul className="notif__list" style={{ maxHeight: 'none' }}>
            {rows.map((item) => {
              const inner = (
                <>
                  <span className={`notif__icon notif__icon--${item.kind}`}>
                    <i className={`bi ${ICONS[item.kind] || ICONS.general}`} aria-hidden="true" />
                  </span>

                  <span className="notif__text">
                    <span className="notif__item-title">{item.title}</span>
                    {item.body && (
                      <span className="notif__body" style={{ WebkitLineClamp: 3 }}>{item.body}</span>
                    )}
                    <span className="notif__when">{formatSince(item.created_at)}</span>
                  </span>

                  {!item.is_read && <span className="notif__dot" aria-label="Unread" />}
                </>
              );

              /*
               * A row with somewhere to go is a link; one without is not. Rows
               * written for staff arrive with their /admin path stripped, and
               * dressing those as links would offer a door that opens on
               * nothing.
               */
              return (
                <li key={item.id}>
                  {item.url ? (
                    <Link href={item.url} className={`notif__item ${item.is_read ? '' : 'is-unread'}`}>
                      {inner}
                    </Link>
                  ) : (
                    <div className={`notif__item ${item.is_read ? '' : 'is-unread'}`}>{inner}</div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>

      {payload.meta && <Pagination meta={payload.meta} basePath="/account/notifications" />}
    </div>
  );
}
