'use client';

import Link from 'next/link';
import { useParams, useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import OrderTimeline from '@/components/account/OrderTimeline';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';

// How far along each goat is, shown per item because an order can be supplied
// by more than one farm.
const ITEM_PROGRESS = {
  pending: 'text-bg-secondary',
  preparing: 'text-bg-warning',
  ready: 'text-bg-info',
  handed_over: 'text-bg-success',
  cancelled: 'text-bg-danger',
};

export default function OrderDetailPage() {
  const { number } = useParams();
  const searchParams = useSearchParams();
  const { token } = useAuth();
  const settings = useSettings();

  const [order, setOrder] = useState(null);
  const [cancelling, setCancelling] = useState(false);

  const justPlaced = searchParams.get('placed') === '1';

  useEffect(() => {
    if (!token) return;

    apiFetch(`/orders/${number}`, { token })
      .then((response) => setOrder(response.data))
      .catch(() => setOrder(false));
  }, [token, number]);

  const cancel = async () => {
    if (!window.confirm('Cancel this order? The goat goes back on sale.')) return;

    setCancelling(true);
    try {
      const response = await apiFetch(`/orders/${number}/cancel`, { method: 'POST', token });
      toast.success(response.message);

      const refreshed = await apiFetch(`/orders/${number}`, { token });
      setOrder(refreshed.data);
    } catch (error) {
      toast.error(error.message || 'Could not cancel this order.');
    } finally {
      setCancelling(false);
    }
  };

  if (order === null) {
    return <div className="panel text-center py-5"><span className="spinner-border text-brand" /></div>;
  }

  if (order === false) {
    return (
      <div className="panel">
        <div className="empty">
          <i className="bi bi-question-circle" />
          <h1 className="h5">Order not found</h1>
          <Link href="/account" className="btn btn-brand px-4">Back to my orders</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="d-grid gap-4">
      {justPlaced && (
        <div className="alert alert-success mb-0">
          <i className="bi bi-check-circle-fill me-2" />
          Thanks — your order is in. We will call {order.customer.phone} to confirm before delivery.
        </div>
      )}

      <div className="panel">
        <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
          <div>
            <Link href="/account" className="small text-soft d-block mb-1">
              <i className="bi bi-arrow-left me-1" />All orders
            </Link>
            <h1 className="h4 mb-1">{order.order_number}</h1>
            <div className="small text-soft">Placed {formatDateTime(order.placed_at)}</div>
          </div>

          {order.is_cancellable && (
            <button className="btn btn-outline-danger btn-sm" onClick={cancel} disabled={cancelling}>
              {cancelling ? 'Cancelling…' : 'Cancel order'}
            </button>
          )}
        </div>

        <OrderTimeline status={order.status} />
      </div>

      <div className="panel">
        <h2 className="h6 mb-3">Items</h2>
        {order.items?.map((item, index) => (
          <div className={`d-flex gap-3 py-3 ${index > 0 ? 'border-top' : ''}`} key={item.id}>
            <div className="gallery__thumb" style={{ width: 72, aspectRatio: '1' }}>
              {item.thumbnail
                ? <img src={item.thumbnail} alt={item.name} />
                : <div className="card-goat__empty"><i className="bi bi-image" /></div>}
            </div>
            <div className="flex-grow-1">
              {item.slug
                ? <Link href={`/goats/${item.slug}`} className="fw-semibold text-body">{item.name}</Link>
                : <span className="fw-semibold">{item.name}</span>}

              <div className="small text-soft">{item.sku} · Qty {item.quantity}</div>

              {item.supplied_by && (
                <div className="small text-soft">
                  <i className="bi bi-shop me-1" aria-hidden="true" />
                  Supplied by {item.supplied_by}
                </div>
              )}

              {item.fulfilment?.label && (
                <span className={`status-pill mt-2 d-inline-block ${ITEM_PROGRESS[item.fulfilment.status] || 'text-bg-secondary'}`}>
                  {item.fulfilment.label}
                </span>
              )}
            </div>
            <div className="fw-bold">{formatMoney(item.line_total, settings)}</div>
          </div>
        ))}
      </div>

      <div className="row g-4">
        <div className="col-md-6">
          <div className="panel h-100">
            <h2 className="h6 mb-3">Delivery address</h2>
            <address className="mb-0 small">
              <strong>{order.customer.name}</strong><br />
              {order.customer.phone}<br />
              {order.shipping.address_line}<br />
              {order.shipping.area && <>{order.shipping.area}<br /></>}
              {order.shipping.city} {order.shipping.postal_code}
            </address>
            {order.shipping.notes && (
              <p className="small text-soft mt-2 mb-0"><em>{order.shipping.notes}</em></p>
            )}
          </div>
        </div>

        <div className="col-md-6">
          <div className="panel h-100">
            <h2 className="h6 mb-3">Payment</h2>
            <dl className="row small mb-0">
              <dt className="col-7 fw-normal text-soft">Method</dt>
              <dd className="col-5 text-end text-uppercase">{order.payment_method}</dd>

              <dt className="col-7 fw-normal text-soft">Subtotal</dt>
              <dd className="col-5 text-end">{formatMoney(order.totals.subtotal, settings)}</dd>

              {order.totals.discount > 0 && (
                <>
                  <dt className="col-7 fw-normal text-soft">Discount</dt>
                  <dd className="col-5 text-end text-success">−{formatMoney(order.totals.discount, settings)}</dd>
                </>
              )}

              <dt className="col-7 fw-normal text-soft">Delivery</dt>
              <dd className="col-5 text-end">{formatMoney(order.totals.delivery_charge, settings)}</dd>

              <dt className="col-7 fw-semibold pt-2 border-top">Total</dt>
              <dd className="col-5 text-end fw-bold text-brand pt-2 border-top">
                {formatMoney(order.totals.total, settings)}
              </dd>
            </dl>
          </div>
        </div>
      </div>
    </div>
  );
}
