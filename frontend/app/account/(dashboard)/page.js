'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useCallback, useState } from 'react';
import Pagination from '@/components/ui/Pagination';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { useLiveRefresh } from '@/lib/useLiveRefresh';
import { formatDate, formatMoney } from '@/lib/format';

const STATUS_COLORS = {
  pending: 'text-bg-warning',
  confirmed: 'text-bg-info',
  processing: 'text-bg-primary',
  out_for_delivery: 'text-bg-info',
  delivered: 'text-bg-success',
  cancelled: 'text-bg-danger',
};

export default function OrdersPage() {
  const { token } = useAuth();
  const settings = useSettings();
  const searchParams = useSearchParams();
  const page = searchParams.get('page') || '1';

  const [payload, setPayload] = useState(null);

  const load = useCallback(async () => {
    if (!token) return;

    try {
      setPayload(await apiFetch(`/orders?page=${page}`, { token }));
    } catch {
      setPayload({ data: [] });
    }
  }, [token, page]);

  // Every status in this list is one staff can move. Same reasoning as the
  // order page: coming back to the tab is when it gets read again.
  useLiveRefresh(load, { immediate: true, enabled: Boolean(token) });

  if (payload === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  const orders = payload.data || [];

  if (!orders.length) {
    return (
      <div className="panel">
        <div className="empty">
          <i className="bi bi-box-seam" />
          <h1 className="h5">No orders yet</h1>
          <p>When you buy a goat it will show up here with its delivery status.</p>
          <Link href="/shop" className="btn btn-brand px-4">Browse goats</Link>
        </div>
      </div>
    );
  }

  return (
    <div>
      <h1 className="h4 mb-4">My orders</h1>

      {orders.map((order) => {
        const items = order.items || [];
        const names = items.map((item) => item.name).filter(Boolean).join(' · ');

        return (
          <article className="order-card" key={order.id}>
            {/*
              * The facts you scan a list of orders for -- when, how much, which
              * one -- laid out as labelled columns above the goats themselves.
              * A date and a total buried in the body is what makes an order
              * list something you have to read rather than scan.
              */}
            <div className="order-card__head">
              <div>
                <span>Order placed</span>
                <strong>{formatDate(order.placed_at)}</strong>
              </div>
              <div>
                <span>Total</span>
                <strong>{formatMoney(order.totals.total, settings)}</strong>
              </div>
              <div className="min-w-0">
                <span>Order number</span>
                <strong className="d-block text-truncate">{order.order_number}</strong>
              </div>

              {/* Stretched, so the whole card stays clickable the way it always
                  was, while the affordance is finally visible. */}
              <div className="order-card__action">
                <Link
                  href={`/account/orders/${order.order_number}`}
                  className="btn btn-quiet btn-sm stretched-link"
                >
                  View order <i className="bi bi-arrow-right ms-1" aria-hidden="true" />
                </Link>
              </div>
            </div>

            <div className="order-card__body">
              <div className="d-flex gap-2 flex-shrink-0">
                {items.slice(0, 3).map((item) => (
                  <div className="gallery__thumb" style={{ width: 56, aspectRatio: '1' }} key={item.id}>
                    {item.thumbnail
                      ? <img src={item.thumbnail} alt={item.name} />
                      : <div className="card-goat__empty"><i className="bi bi-image" /></div>}
                  </div>
                ))}
                {items.length > 3 && (
                  <span className="align-self-center small text-soft">+{items.length - 3}</span>
                )}
              </div>

              <div className="flex-grow-1 min-w-0">
                <span className={`status-pill ${STATUS_COLORS[order.status] || 'text-bg-secondary'}`}>
                  {order.status_label}
                </span>

                {/* The animals, by name. "1 item" describes a parcel; this is
                    a list of goats, and the buyer knows them by name. */}
                {names && <div className="small text-soft text-truncate mt-2">{names}</div>}
              </div>
            </div>
          </article>
        );
      })}

      {/* The list was fetching page one and quietly dropping the rest: past ten
          orders, the older ones were unreachable from here. */}
      <Pagination meta={payload.meta} basePath="/account" />
    </div>
  );
}
