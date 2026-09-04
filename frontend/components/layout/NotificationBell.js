'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useAuth } from '@/context/AuthContext';
import { apiFetch } from '@/lib/api';
import { formatSince } from '@/lib/format';
import { useLiveRefresh } from '@/lib/useLiveRefresh';

/**
 * The bell.
 *
 * Placed with the other header icons rather than inside the account menu, which
 * is where every large shop puts it -- Daraz, Flipkart, Shopee and Myntra all
 * keep a badged bell in the top-right cluster. The reason is the badge: a count
 * buried in a menu is a count nobody sees, and the whole value of this is being
 * able to tell at a glance that something happened without opening anything.
 *
 * Signed-in people only. Notifications belong to an account, and a bell that is
 * permanently empty teaches people to ignore it.
 *
 * The panel is React state rather than a Bootstrap dropdown on purpose.
 * Bootstrap's dismiss handler calls preventDefault() on anchors -- which is
 * exactly how the mobile drawer's links stopped navigating -- so owning the
 * open state means a link in here is simply a link.
 */
const ICONS = {
  order: 'bi-box-seam',
  payment: 'bi-cash-coin',
  refund: 'bi-arrow-counterclockwise',
  booking: 'bi-house-door',
  general: 'bi-bell',
};

// Enough to be worth opening, few enough to read standing up. The rest live on
// the notifications page.
const PANEL_LIMIT = 6;

export default function NotificationBell() {
  const { token, isAuthenticated } = useAuth();
  const router = useRouter();

  const [open, setOpen] = useState(false);
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);
  const [badge, setBadge] = useState('0');
  const [busy, setBusy] = useState(false);

  const wrapper = useRef(null);

  const load = useCallback(async () => {
    if (!token) return;

    const response = await apiFetch('/notifications', { token });

    setItems(response.data || []);
    setUnread(response.meta?.unread || 0);
    setBadge(response.meta?.unread_badge || '0');
  }, [token]);

  /*
   * Polled rather than pushed. A shop this size does not need a websocket to
   * say a goat is being prepared, and this is the helper that already refreshes
   * an open order page -- so the bell catches up when the tab comes back into
   * focus, which is when somebody is actually looking at it.
   */
  useLiveRefresh(load, { immediate: true, enabled: Boolean(token) });

  // A click anywhere else, or Escape, closes it. Without the first of these the
  // panel follows you around the page.
  useEffect(() => {
    if (!open) return undefined;

    const onDown = (event) => {
      if (!wrapper.current?.contains(event.target)) setOpen(false);
    };
    const onKey = (event) => {
      if (event.key === 'Escape') setOpen(false);
    };

    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);

    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  if (!isAuthenticated) return null;

  const openItem = async (item) => {
    setOpen(false);

    // Navigate first. Marking it read is bookkeeping; making somebody wait for
    // a round trip before the page moves is not.
    if (item.url) router.push(item.url);

    if (item.is_read) return;

    try {
      const response = await apiFetch(`/notifications/${item.id}/read`, { method: 'POST', token });

      setUnread(response.meta?.unread ?? 0);
      setItems((current) => current.map((row) => (
        row.id === item.id ? { ...row, is_read: true } : row
      )));
    } catch {
      // A notification that stays bold is a small thing. An error toast in
      // front of the page they just asked for is not.
    }
  };

  const readAll = async () => {
    setBusy(true);

    try {
      await apiFetch('/notifications/read-all', { method: 'POST', token });

      setUnread(0);
      setBadge('0');
      setItems((current) => current.map((row) => ({ ...row, is_read: true })));
    } catch {
      // Same reasoning as above.
    } finally {
      setBusy(false);
    }
  };

  const shown = items.slice(0, PANEL_LIMIT);

  return (
    <div className="notif" ref={wrapper}>
      <button
        type="button"
        className="icon-btn"
        onClick={() => setOpen((was) => !was)}
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        aria-expanded={open}
        aria-haspopup="true"
      >
        <i className={`bi ${unread > 0 ? 'bi-bell-fill' : 'bi-bell'}`} aria-hidden="true" />
        {unread > 0 && <span className="icon-btn__count">{badge}</span>}
      </button>

      {open && (
        <div className="notif__panel" role="dialog" aria-label="Notifications">
          <div className="notif__head">
            <h2 className="notif__title">Notifications</h2>
            {unread > 0 && (
              <button type="button" className="notif__clear" onClick={readAll} disabled={busy}>
                {busy ? 'Marking…' : 'Mark all read'}
              </button>
            )}
          </div>

          {shown.length === 0 ? (
            <div className="notif__empty">
              <i className="bi bi-bell-slash" aria-hidden="true" />
              <p className="mb-0">Nothing yet. We will tell you here when something happens.</p>
            </div>
          ) : (
            <ul className="notif__list">
              {shown.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    className={`notif__item ${item.is_read ? '' : 'is-unread'}`}
                    onClick={() => openItem(item)}
                  >
                    <span className={`notif__icon notif__icon--${item.kind}`}>
                      <i className={`bi ${ICONS[item.kind] || ICONS.general}`} aria-hidden="true" />
                    </span>

                    <span className="notif__text">
                      <span className="notif__item-title">{item.title}</span>
                      {item.body && <span className="notif__body">{item.body}</span>}
                      <span className="notif__when">{formatSince(item.created_at)}</span>
                    </span>

                    {/* Marks the row without spending a word on it, which the
                        bold text is already doing. */}
                    {!item.is_read && <span className="notif__dot" aria-label="Unread" />}
                  </button>
                </li>
              ))}
            </ul>
          )}

          <Link href="/account/notifications" className="notif__all" onClick={() => setOpen(false)}>
            See all notifications
          </Link>
        </div>
      )}
    </div>
  );
}
