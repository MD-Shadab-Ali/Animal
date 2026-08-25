'use client';

import Link from 'next/link';
import { useParams, useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import OrderPayment from '@/components/account/OrderPayment';
import OrderRefund from '@/components/account/OrderRefund';
import OrderTimeline from '@/components/account/OrderTimeline';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
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
  const [confirmingCancel, setConfirmingCancel] = useState(false);

  // Bumped after a payment is submitted so the balance and the history reload.
  const [reloads, setReloads] = useState(0);

  // The ?placed=1 flag lives in the URL, so it outlives whatever happens to the
  // order next. Greeting someone with "your order is in" after they cancelled
  // it — or after it has already moved on — is worse than saying nothing.
  const justPlaced = searchParams.get('placed') === '1';

  useEffect(() => {
    if (!token) return;

    apiFetch(`/orders/${number}`, { token })
      .then((response) => setOrder(response.data))
      .catch(() => setOrder(false));
  }, [token, number, reloads]);

  const cancel = async () => {
    setConfirmingCancel(false);
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

  // Cancelling is allowed right up to the handover, so by now the goat may be
  // penned and the money may already be ours. Say both, if either is true.
  const paidSoFar = Number(order?.totals?.paid || 0);

  const cancelWarnings = [
    'The goat goes back on sale, and we will let the farm know.',
    order.status === 'out_for_delivery'
      ? 'It is already on its way to you, so please tell us as soon as you can.'
      : '',
    paidSoFar > 0
      ? `You have paid ${formatMoney(paidSoFar, settings)} — you can ask for it back afterwards.`
      : '',
  ];

  return (
    <div className="d-grid gap-4">
      <ConfirmDialog
        open={confirmingCancel}
        title={`Cancel order ${order.order_number}?`}
        lines={cancelWarnings}
        confirmLabel="Yes, cancel it"
        cancelLabel="Keep my order"
        busy={cancelling}
        onConfirm={cancel}
        onCancel={() => setConfirmingCancel(false)}
      />

      {justPlaced && order.status === 'pending' && (
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
            <button
              className="btn btn-outline-danger btn-sm"
              onClick={() => setConfirmingCancel(true)}
              disabled={cancelling}
            >
              {cancelling ? 'Cancelling…' : 'Cancel order'}
            </button>
          )}
        </div>

        <OrderTimeline
          status={order.status}
          paid={order.refund?.amount || 0}
          refunded={order.refund?.sent || 0}
          formatAmount={(amount) => formatMoney(amount, settings)}
        />
      </div>

      <OrderPayment order={order} onPaid={() => setReloads((count) => count + 1)} />

      <OrderRefund order={order} onRequested={() => setReloads((count) => count + 1)} />

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
              <dd className="col-5 text-end">
                {order.payment?.status === 'paid'
                  ? <span className="text-success">Paid</span>
                  : <span className="text-soft text-uppercase">{order.payment_method}</span>}
              </dd>

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

              {order.totals.paid > 0 && (
                <>
                  <dt className="col-7 fw-normal text-soft">Received</dt>
                  <dd className="col-5 text-end text-success">
                    {formatMoney(order.totals.paid, settings)}
                  </dd>
                </>
              )}

              {order.status === 'cancelled' ? (
                order.refund?.amount > 0 && (
                  <>
                    <dt className="col-7 fw-semibold">To be refunded</dt>
                    <dd className="col-5 text-end fw-semibold text-danger">
                      {formatMoney(order.refund.amount, settings)}
                    </dd>
                  </>
                )
              ) : (
                order.totals.balance_due > 0 && (
                  <>
                    <dt className="col-7 fw-semibold">Still to pay</dt>
                    <dd className="col-5 text-end fw-semibold">
                      {formatMoney(order.totals.balance_due, settings)}
                    </dd>
                  </>
                )
              )}
            </dl>
          </div>
        </div>
      </div>
    </div>
  );
}
