'use client';

import { useCallback, useState } from 'react';
import FulfilmentControl from '@/components/seller/FulfilmentControl';
import OrderStatusControl from '@/components/seller/OrderStatusControl';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { useLiveRefresh } from '@/lib/useLiveRefresh';
import { formatDate, formatMoney } from '@/lib/format';

const STATUS_CLASS = {
  pending: 'text-bg-warning',
  confirmed: 'text-bg-info',
  processing: 'text-bg-primary',
  out_for_delivery: 'text-bg-info',
  delivered: 'text-bg-success',
  cancelled: 'text-bg-danger',
};

export default function SellerOrdersPage() {
  const { token } = useAuth();
  const settings = useSettings();
  const [orders, setOrders] = useState(null);

  const load = useCallback(async () => {
    if (!token) return;

    try {
      const response = await apiFetch('/seller/orders', { token });
      setOrders(response.data || []);
    } catch {
      setOrders([]);
    }
  }, [token]);

  // A seller watching this list has no idea an order moved in the admin panel.
  useLiveRefresh(load, { immediate: true, enabled: Boolean(token) });

  // What the child rows call after acting on an order; it used to bump a key.
  const refresh = load;

  if (orders === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (!orders.length) {
    return (
      <div>
        <h1 className="h4 mb-4">Sales</h1>
        <div className="panel">
          <div className="empty">
            <i className="bi bi-box-seam empty__icon" aria-hidden="true" />
            <h2 className="h5">No sales yet</h2>
            <p className="mb-0">When a buyer orders one of your goats it will show up here.</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div>
      <h1 className="h4 mb-4">Sales</h1>

      <div className="d-grid gap-3">
        {orders.map((order) => (
          <div className="panel" key={order.order_number}>
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
              <div>
                <strong className="text-ink">{order.order_number}</strong>
                <div className="small text-soft">Placed {formatDate(order.placed_at)}</div>
              </div>
              <span className={`status-pill ${STATUS_CLASS[order.status] || 'text-bg-secondary'}`}>
                {order.status_label}
              </span>
            </div>

            <div className="mb-3">
              {order.items.map((item, index) => (
                <div className={`d-flex flex-wrap gap-3 py-2 ${index > 0 ? 'border-top' : ''}`} key={item.id}>
                  <div className="gallery__thumb" style={{ width: 56, aspectRatio: '1', flexShrink: 0 }}>
                    {item.thumbnail
                      ? <img src={item.thumbnail} alt="" />
                      : <div className="card-goat__empty"><i className="bi bi-image" aria-hidden="true" /></div>}
                  </div>

                  <div className="flex-grow-1" style={{ minWidth: 160 }}>
                    <div className="fw-semibold text-ink small">{item.name}</div>
                    <div className="small text-soft">
                      {item.sku}
                      {/* Which weight to prepare — the listing's name cannot
                          say, because it is sold across a range. */}
                      {item.weight_kg ? ` · ${item.weight_kg} kg` : ''}
                      {` · Qty ${item.quantity}`}
                    </div>
                  </div>

                  <div className="text-end small">
                    <div>Sold for <strong>{formatMoney(item.line_total, settings)}</strong></div>
                    <div className="text-soft">Commission −{formatMoney(item.commission, settings)}</div>
                    <div className="fw-bold text-brand">You earn {formatMoney(item.earning, settings)}</div>
                    {item.paid_out && <div className="text-success small">Paid out</div>}
                  </div>

                  {!order.you_manage && (
                    <div className="w-100">
                      <FulfilmentControl item={item} onUpdated={refresh} />
                    </div>
                  )}
                </div>
              ))}
            </div>

            {order.you_manage && <OrderStatusControl order={order} onUpdated={refresh} />}

            <div className="d-flex flex-wrap justify-content-between gap-3 pt-3 border-top small">
              <div>
                <div className="text-soft">Buyer</div>
                <div className="fw-semibold text-ink">{order.buyer.name}</div>
                <div className="text-soft">
                  {order.buyer.area ? `${order.buyer.area}, ` : ''}{order.buyer.city}
                </div>
                {order.buyer.phone
                  ? <a href={`tel:${order.buyer.phone}`}>{order.buyer.phone}</a>
                  : <span className="text-soft">Contact shown once the order is confirmed</span>}
              </div>

              <div className="text-end" style={{ minWidth: 220 }}>
                <dl className="row mb-2 small">
                  <dt className="col-7 fw-normal text-soft">Goats sold for</dt>
                  <dd className="col-5 mb-1">{formatMoney(order.totals.gross, settings)}</dd>

                  <dt className="col-7 fw-normal text-soft">Commission</dt>
                  <dd className="col-5 mb-1">−{formatMoney(order.totals.commission, settings)}</dd>

                  {order.totals.delivery_charge > 0 && (
                    <>
                      <dt className="col-7 fw-normal text-soft">
                        Delivery
                        {!order.totals.delivery_is_yours && (
                          <span className="d-block" style={{ fontSize: '.75rem' }}>we deliver this one</span>
                        )}
                      </dt>
                      <dd className="col-5 mb-1">
                        {order.totals.delivery_is_yours
                          ? `+${formatMoney(order.totals.delivery_earning, settings)}`
                          : <span className="text-soft">{formatMoney(order.totals.delivery_charge, settings)}</span>}
                      </dd>
                    </>
                  )}
                </dl>

                <div className="text-soft">You earn</div>
                <div className="h6 mb-0 text-brand fw-bold">
                  {formatMoney(order.totals.earning, settings)}
                </div>

                {order.totals.buyer_paid > 0 && (
                  <div className="text-soft mt-1" style={{ fontSize: '.75rem' }}>
                    Buyer paid {formatMoney(order.totals.buyer_paid, settings)}
                  </div>
                )}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
