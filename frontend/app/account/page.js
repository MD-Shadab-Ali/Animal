'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
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
  const [orders, setOrders] = useState(null);

  useEffect(() => {
    if (!token) return;

    apiFetch('/orders', { token })
      .then((response) => setOrders(response.data || []))
      .catch(() => setOrders([]));
  }, [token]);

  if (orders === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

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

      <div className="d-grid gap-3">
        {orders.map((order) => (
          <Link href={`/account/orders/${order.order_number}`} className="panel text-body d-block" key={order.id}>
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
              <div>
                <strong>{order.order_number}</strong>
                <div className="small text-soft">Placed {formatDate(order.placed_at)}</div>
              </div>
              <span className={`status-pill ${STATUS_COLORS[order.status] || 'text-bg-secondary'}`}>
                {order.status_label}
              </span>
            </div>

            <div className="d-flex flex-wrap justify-content-between align-items-end gap-3">
              <div className="d-flex gap-2">
                {order.items?.slice(0, 3).map((item) => (
                  <div className="gallery__thumb" style={{ width: 56, aspectRatio: '1' }} key={item.id}>
                    {item.thumbnail
                      ? <img src={item.thumbnail} alt={item.name} />
                      : <div className="card-goat__empty"><i className="bi bi-image" /></div>}
                  </div>
                ))}
                {order.items?.length > 3 && (
                  <span className="align-self-center small text-soft">+{order.items.length - 3} more</span>
                )}
              </div>

              <div className="text-end">
                <div className="small text-soft">{order.items?.length} item{order.items?.length === 1 ? '' : 's'}</div>
                <div className="h6 mb-0 text-brand fw-bold">{formatMoney(order.totals.total, settings)}</div>
              </div>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}
