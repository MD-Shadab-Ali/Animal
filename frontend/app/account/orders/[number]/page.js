'use client';

import Link from 'next/link';
import { useParams, useSearchParams } from 'next/navigation';
import { useCallback, useState } from 'react';
import toast from 'react-hot-toast';
import OrderPayment from '@/components/account/OrderPayment';
import OrderRefund from '@/components/account/OrderRefund';
import OrderTimeline, { BUYER_STATUS_LABELS } from '@/components/account/OrderTimeline';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import { useAuth } from '@/context/AuthContext';
import { useSettings } from '@/context/SiteContext';
import { useLiveRefresh } from '@/lib/useLiveRefresh';
import { apiFetch } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';

// How far along each goat is, shown per item because an order can be supplied
// by more than one farm.
const ITEM_PROGRESS = {
  pending: 'text-bg-secondary',
  preparing: 'text-bg-warning',
  ready: 'text-bg-info',
  handed_over: 'text-bg-success',
  // Set by the API once the order itself is delivered — the goat is with the
  // buyer, not with the courier.
  delivered: 'text-bg-success',
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
  const [confirmingReceipt, setConfirmingReceipt] = useState(false);
  const [receipting, setReceipting] = useState(false);
  const [confirmingOnWay, setConfirmingOnWay] = useState(false);
  const [settingOff, setSettingOff] = useState(false);

  // The ?placed=1 flag lives in the URL, so it outlives whatever happens to the
  // order next. Greeting someone with "your order is in" after they cancelled
  // it — or after it has already moved on — is worse than saying nothing.
  const justPlaced = searchParams.get('placed') === '1';

  /*
   * What the payment provider sent them back with. Only ever a label: the
   * order's own payment figures come from the server, which decided them by
   * asking the provider directly.
   */
  const paymentResult = searchParams.get('payment');

  const load = useCallback(async () => {
    if (!token) return;

    try {
      const response = await apiFetch(`/orders/${number}`, { token });
      setOrder(response.data);
    } catch {
      setOrder(false);
    }
  }, [token, number]);

  /*
   * Staff move an order along from the admin panel, and this page had no way
   * of knowing. Left alone it went on showing "Confirmed" long after the goat
   * was being prepared, which reads as nothing having happened.
   *
   * A settled order has nowhere further to go, so it stops watching -- but it
   * is still loaded once, which is why the first fetch is not tied to that.
   */
  const settled = order && ['delivered', 'cancelled'].includes(order.status);

  useLiveRefresh(load, {
    immediate: true,
    enabled: Boolean(token) && ! settled,
  });

  // The buyer is the one person who actually knows the goat turned up, so
  // saying so closes the order — which is also what releases the seller's money.
  const confirmReceipt = async () => {
    setConfirmingReceipt(false);
    setReceipting(true);

    try {
      const response = await apiFetch(`/orders/${number}/received`, { method: 'POST', token });
      toast.success(response.message);
      setOrder(response.data);
    } catch (error) {
      toast.error(error.message || 'Could not confirm that just now.');
    } finally {
      setReceipting(false);
    }
  };

  /*
   * Collection only. Nobody but the buyer knows they have set off -- there is no
   * driver to report it, and staff marking it would be guessing at somebody
   * else's afternoon.
   */
  const onMyWay = async () => {
    setConfirmingOnWay(false);
    setSettingOff(true);

    try {
      const response = await apiFetch(`/orders/${number}/on-my-way`, { method: 'POST', token });
      toast.success(response.message);
      setOrder(response.data);
    } catch (error) {
      toast.error(error.message || 'Could not update that just now.');
    } finally {
      setSettingOff(false);
    }
  };

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
  const isCancelled = order.status === 'cancelled';
  const refundSent = Number(order?.refund?.sent || 0);
  const itemCount = order.items?.length || 0;

  const cancelWarnings = [
    'The goat goes back on sale, and we will let the farm know.',
    order.status === 'out_for_delivery'
      ? 'It is already on its way to you, so please tell us as soon as you can.'
      : '',
    paidSoFar > 0
      ? `You have paid ${formatMoney(paidSoFar, settings)} — you can ask for it back afterwards.`
      : '',
  ];

  // Steps staff actually wrote something or attached a photo to. A bare status
  // change is already drawn by the tracker, so an empty row would add a line
  // to the page and nothing to what the buyer knows.
  //
  // `pending` is dropped with it: the observer writes an "Order placed" row the
  // moment an order exists, which is not staff telling the buyer anything --
  // the tracker above already says Placed, with the same timestamp.
  const updates = (order.history || [])
    .filter((entry) => entry.status !== 'pending')
    .filter((entry) => entry.note || entry.photo)
    /*
     * Words expire, photographs do not. "Preparing — here is your goat" is
     * worth reading while the goat is being prepared; under a delivered order
     * it describes something that finished days ago, and the tracker above
     * already says where the order actually got to. The photo is the one thing
     * that never goes stale -- it is still the only picture of the animal the
     * buyer bought -- so a past step keeps its photo and loses its text, and
     * a past step that never had a photo drops out of the list entirely.
     */
    .filter((entry) => entry.photo || entry.status === order.status);

  /*
   * The tag codes for the animals actually set aside, shown beside the farm's
   * photograph of them. A code is only worth printing next to a picture if it
   * is the code on that animal's pen -- so these come off the order's own
   * lines, not from anything in the update itself.
   */
  const taggedAnimals = (order.items || [])
    .map((item) => item.animal)
    .filter((animal) => animal?.qr);

  /*
   * Which update the codes hang off. The photograph is where they belong --
   * picture and code, read together at handover -- but an animal nobody has
   * photographed still has a pen tag worth checking, so they fall back to the
   * step the order is on rather than disappearing with the photo.
   */
  const codeRowIndex = updates.findIndex((entry) => entry.photo) >= 0
    ? updates.findIndex((entry) => entry.photo)
    : updates.findIndex((entry) => entry.status === order.status);

  return (
    <div className="d-grid gap-4">
      <ConfirmDialog
        open={confirmingReceipt}
        title="Has your goat arrived?"
        lines={[
          'Only say yes once the animal is actually with you.',
          'This closes the order and pays the farm, so it cannot be undone.',
        ]}
        confirmLabel="Yes, it arrived"
        cancelLabel="Not yet"
        tone="brand"
        busy={receipting}
        onConfirm={confirmReceipt}
        onCancel={() => setConfirmingReceipt(false)}
      />

      <ConfirmDialog
        open={confirmingOnWay}
        title="Setting off to collect?"
        lines={[
          'We will have the goat penned and ready for you.',
          'Only say yes once you are actually on your way.',
        ]}
        confirmLabel="Yes, I'm on my way"
        cancelLabel="Not yet"
        tone="brand"
        busy={settingOff}
        onConfirm={onMyWay}
        onCancel={() => setConfirmingOnWay(false)}
      />

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

      {/*
        * The page's own header, outside every panel. A single order is a
        * document: it names itself once at the top, says when it was placed and
        * what it came to, and keeps the one destructive action at arm's length
        * on the right.
        */}
      <div>
        <Link href="/account" className="small text-soft d-inline-flex align-items-center gap-1 mb-2">
          <i className="bi bi-arrow-left" aria-hidden="true" />All orders
        </Link>

        <div className="d-flex flex-wrap align-items-start justify-content-between gap-3">
          <div className="min-w-0">
            <h1 className="h4 mb-1">Order {order.order_number}</h1>
            <div className="small text-soft">
              Placed {formatDateTime(order.placed_at)}
              {itemCount > 0 && ` · ${itemCount} ${itemCount === 1 ? 'goat' : 'goats'}`}
              {` · ${formatMoney(order.totals.total, settings)}`}
            </div>
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
      </div>

      {/* Deliberately inline, not a modal. The buyer has just finished placing
          the order and wants to see it; a dialog puts a wall in front of the
          very thing they came for, to confirm something the page below already
          proves. It also has to name the *next* step, which depends on how they
          chose to pay — promising a phone call while a "pay now" panel sits
          underneath it is worse than saying nothing. */}
      {paymentResult === 'success' && (
        <div className="alert alert-success mb-0 d-flex gap-3 align-items-start">
          <i className="bi bi-check-circle-fill fs-5 lh-1 mt-1" aria-hidden="true" />
          <div>
            <strong className="d-block">Payment received.</strong>
            <span className="small">
              Nothing more to do — we have your money and we are holding your goat.
            </span>
          </div>
        </div>
      )}

      {paymentResult === 'pending' && (
        <div className="alert alert-warning mb-0 d-flex gap-3 align-items-start">
          <i className="bi bi-hourglass-split fs-5 lh-1 mt-1" aria-hidden="true" />
          <div>
            <strong className="d-block">Your payment is still going through.</strong>
            <span className="small">
              We are waiting on your provider. This page updates itself once they confirm —
              there is no need to pay again.
            </span>
          </div>
        </div>
      )}

      {paymentResult === 'failed' && (
        <div className="alert alert-danger mb-0 d-flex gap-3 align-items-start">
          <i className="bi bi-x-circle-fill fs-5 lh-1 mt-1" aria-hidden="true" />
          <div>
            <strong className="d-block">That payment did not go through.</strong>
            <span className="small">
              Nothing has been taken. Your order is still here — you can try paying again below.
            </span>
          </div>
        </div>
      )}

      {/* One greeting at a time: a payment result is the more useful of the two. */}
      {justPlaced && ! paymentResult && order.status === 'pending' && (
        <div className="alert alert-success mb-0 d-flex gap-3 align-items-start">
          <i className="bi bi-check-circle-fill fs-5 lh-1 mt-1" aria-hidden="true" />
          <div>
            <strong className="d-block">Thanks — order {order.order_number} is in.</strong>
            <span className="small">
              {order.payment?.is_due && order.payment?.amount_due_now > 0
                ? `Next: send ${formatMoney(order.payment.amount_due_now, settings)} using the details below, then tell us — we hold your goat as soon as it lands.`
                : `We will call ${order.customer.phone} to confirm before delivery.`}
            </span>
          </div>
        </div>
      )}

      <div className="order-grid">
        {/* Everything still in motion, in the order it happens. */}
        <div className="order-grid__main">
          <div className="panel">
            <OrderTimeline
              status={order.status}
              collection={Boolean(order.pickup)}
              paid={order.refund?.amount || 0}
              refunded={order.refund?.sent || 0}
              formatAmount={(amount) => formatMoney(amount, settings)}
              estimate={order.shipping?.estimate}
              deliveredAt={order.delivered_at}
              formatWhen={formatDateTime}
              history={order.history}
              placedAt={order.placed_at}
            />

            {/* Nobody knows better than the buyer whether the goat is standing
                in their yard, so let them say so instead of waiting on a phone
                call being relayed to staff. Kept against the tracker, because
                the tracker is the thing it moves. */}
            {/* Collection only, and only once the goat is prepared -- the two
                buyer steps run in order, so this comes before "it arrived". */}
            {order.pickup && order.status === 'processing' && (
              <div className="mt-4 pt-3 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span className="small text-soft">
                  <i className="bi bi-signpost-split me-1" aria-hidden="true" />
                  Heading over? Tell us and we will have the goat ready.
                </span>

                <button
                  type="button"
                  className="btn btn-brand btn-sm px-3"
                  onClick={() => setConfirmingOnWay(true)}
                  disabled={settingOff}
                >
                  {settingOff ? 'Updating…' : "I'm on my way"}
                </button>
              </div>
            )}

            {order.can_confirm_receipt && (
              <div className="mt-4 pt-3 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span className="small text-soft">
                  <i className="bi bi-house-check me-1" aria-hidden="true" />
                  Has it arrived? Let us know and we will close the order.
                </span>

                <button
                  type="button"
                  className="btn btn-brand btn-sm px-3"
                  onClick={() => setConfirmingReceipt(true)}
                  disabled={receipting}
                >
                  {receipting ? 'Confirming…' : 'Yes, my goat arrived'}
                </button>
              </div>
            )}
          </div>

          {/* What staff wrote and photographed as the order moved.
              The tracker above says which step the order is on; this says what
              actually happened at each one. It matters most at Preparing: the
              listing photo was taken before the buyer ordered, and on a listing
              sold by weight it may not even be the animal they are getting. */}
          {updates.length > 0 && (
            <div className="panel">
              <h2 className="h6 mb-3">Updates from the farm</h2>

              {updates.map((entry, index) => (
                <div className="update-row" key={`${entry.status}-${entry.created_at}-${index}`}>
                  {/* Only the step the order is actually on still has anything
                      to say. Once it moves on, the heading and the note go and
                      the photo is left to speak for itself -- each update named
                      its own step and time, which on a finished order is a date
                      stamp on a message nobody needs to read again. */}
                  {entry.status === order.status && (
                    <>
                      <div className="update-row__meta">
                        <span className="update-row__step">
                          {BUYER_STATUS_LABELS[entry.status] || entry.label}
                        </span>
                        {entry.created_at && (
                          <span className="update-row__when">{formatDateTime(entry.created_at)}</span>
                        )}
                      </div>

                      {entry.note && <div className="small text-ink">{entry.note}</div>}
                    </>
                  )}

                  {/* Big enough to actually see the animal. This is the only
                      picture of the goat the buyer is really getting, so a
                      thumbnail defeats the point of taking it. */}
                  {(entry.photo || index === codeRowIndex) && (
                    <div className="d-flex align-items-start gap-3 flex-wrap mt-1">
                      {entry.photo && (
                        <a
                          href={entry.photo}
                          target="_blank"
                          rel="noreferrer"
                          className="gallery__thumb d-block"
                          style={{ flex: '1 1 16rem', maxWidth: 420, aspectRatio: '4 / 3' }}
                        >
                          <img
                            src={entry.photo}
                            alt={`Your goat at the ${BUYER_STATUS_LABELS[entry.status] || entry.label} step`}
                          />
                        </a>
                      )}

                      {/* The same code that is on the animal's pen, next to the
                          photograph of it. Held up at handover the two either
                          match or they do not, which is the whole point of the
                          tag -- and it is a scan away from the animal's own
                          page, so the check works without this one being open.

                          Every assigned animal gets its own, captioned: one
                          code beside a photograph of several would be claiming
                          to identify whichever the buyer happened to look at. */}
                      {index === codeRowIndex && taggedAnimals.map((animal) => (
                        <figure className="mb-0 text-center" key={animal.qr}>
                          <img
                            src={animal.qr}
                            alt={`Tag code for ${animal.tag || `the ${animal.weight_kg} kg goat`}`}
                            style={{
                              width: 104,
                              height: 104,
                              background: '#fff',
                              padding: 6,
                              borderRadius: 8,
                            }}
                          />
                          {/* Without this the code is just a square. The
                              weight and tag it used to repeat are on the page
                              the scan opens, so printing them here as well only
                              told the buyer what they already had. */}
                          <figcaption className="small text-soft mt-1" style={{ maxWidth: '9rem' }}>
                            {/* Named only when there is more than one, which is
                                the case where the codes are otherwise
                                indistinguishable squares. */}
                            {taggedAnimals.length > 1 && (
                              <strong className="text-ink d-block">
                                {animal.tag || `${animal.weight_kg} kg`}
                              </strong>
                            )}
                            Scan for more about this goat
                          </figcaption>
                        </figure>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}

          <OrderPayment order={order} onPaid={load} />

          <OrderRefund order={order} onRequested={load} />

          <div className="panel">
            <h2 className="h6 mb-3">{itemCount === 1 ? 'Your goat' : 'Your goats'}</h2>
            {order.items?.map((item, index) => (
              <div className={`d-flex gap-3 py-3 ${index > 0 ? 'border-top' : ''}`} key={item.id}>
                <div className="gallery__thumb" style={{ width: 72, aspectRatio: '1' }}>
                  {item.thumbnail
                    ? <img src={item.thumbnail} alt={item.name} />
                    : <div className="card-goat__empty"><i className="bi bi-image" /></div>}
                </div>
                <div className="flex-grow-1 min-w-0">
                  {item.slug
                    ? <Link href={`/goats/${item.slug}`} className="fw-semibold text-body">{item.name}</Link>
                    : <span className="fw-semibold">{item.name}</span>}

                  <div className="small text-soft">
                    {item.sku}
                    {item.weight_kg ? ` · ${item.weight_kg} kg` : ''}
                    {` · Qty ${item.quantity}`}
                  </div>

                  {/* A live animal does not arrive at the weight it left at: it
                      sheds gut fill and water on the road, and the scale at the
                      far end is not the one it was weighed on. Both figures are
                      shown, because hiding the second one is what makes a buyer
                      think they were shortchanged. */}
                  {item.delivered_weight_kg != null && (
                    <div className="small mt-1">
                      {item.weight_direction === 'same' ? (
                        <span className="text-brand">
                          <i className="bi bi-check-circle me-1" aria-hidden="true" />
                          Weighed {item.delivered_weight_kg} kg at delivery — as ordered.
                        </span>
                      ) : (
                        <span className="text-soft">
                          <i className="bi bi-speedometer2 me-1" aria-hidden="true" />
                          Ordered {item.weight_kg} kg · weighed{' '}
                          <strong>{item.delivered_weight_kg} kg</strong> at delivery
                          {item.weight_direction === 'increased' ? ' (heavier)' : ' (lighter)'}.
                          {/* The money follows the scale, so say so on the same
                              line. A changed total with no explanation beside it
                              is the thing that generates the phone call. */}
                          {item.price_adjustment != null && item.price_adjustment !== 0 && (
                            <>
                              {' '}
                              <span className={item.price_adjustment < 0 ? 'text-brand' : 'text-warning'}>
                                {item.price_adjustment < 0 ? '−' : '+'}
                                {formatMoney(Math.abs(item.price_adjustment), settings)}
                                {item.price_adjustment < 0 ? ' off' : ' added'}
                              </span>
                              {' — you pay '}
                              <strong>{formatMoney(item.charged_total, settings)}</strong>.
                            </>
                          )}
                        </span>
                      )}
                    </div>
                  )}

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
        </div>

        {/* The record: what is owed, and where it is going. Settled facts, so
            they sit apart from the half of the page that is still changing. */}
        <aside className="order-grid__aside">
          <div className="panel">
            <h2 className="h6 mb-3">Order summary</h2>
            <dl className="row small mb-0">
              <dt className="col-7 fw-normal text-soft">Method</dt>
              <dd className="col-5 text-end">
                {order.payment?.status === 'refunded'
                  ? <span className="text-soft">Refunded</span>
                  : order.payment?.status === 'paid'
                    ? <span className="text-success">Paid</span>
                    : <span className="text-soft text-uppercase">{order.payment_method}</span>}
              </dd>

              <dt className="col-7 fw-normal text-soft">Subtotal</dt>
              <dd className="col-5 text-end">{formatMoney(order.totals.subtotal, settings)}</dd>

              {/* Sits directly under the subtotal it corrects, so the total
                  below is arithmetic the buyer can follow line by line. */}
              {order.totals.weight_adjustment != null && order.totals.weight_adjustment !== 0 && (
                <>
                  <dt className="col-7 fw-normal text-soft">
                    Weight at delivery
                  </dt>
                  <dd className={`col-5 text-end ${order.totals.weight_adjustment < 0 ? 'text-success' : 'text-warning'}`}>
                    {order.totals.weight_adjustment < 0 ? '−' : '+'}
                    {formatMoney(Math.abs(order.totals.weight_adjustment), settings)}
                  </dd>
                </>
              )}

              {order.totals.discount > 0 && (
                <>
                  <dt className="col-7 fw-normal text-soft">Discount</dt>
                  <dd className="col-5 text-end text-success">−{formatMoney(order.totals.discount, settings)}</dd>
                </>
              )}

              <dt className="col-7 fw-normal text-soft">Delivery</dt>
              <dd className="col-5 text-end">{formatMoney(order.totals.delivery_charge, settings)}</dd>

              {/* The breakdown stays on a cancelled order — it is the record of
                  what was bought, and it is what makes the refund figure below
                  legible. It just stops being the headline: the emphasis and
                  the dividing line move down to what actually happened. */}
              <dt className={`col-7 ${isCancelled ? 'fw-normal text-soft' : 'fw-semibold pt-2 border-top'}`}>
                {isCancelled ? 'Order total' : 'Total'}
              </dt>
              <dd className={`col-5 text-end ${isCancelled ? 'text-soft' : 'fw-bold text-brand pt-2 border-top'}`}>
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

              {refundSent > 0 && (
                <>
                  <dt className="col-7 fw-semibold pt-2 border-top">Refunded to you</dt>
                  <dd className="col-5 text-end fw-bold text-success pt-2 border-top">
                    {formatMoney(refundSent, settings)}
                  </dd>
                </>
              )}

              {isCancelled ? (
                order.refund?.amount > 0 ? (
                  <>
                    <dt className="col-7 fw-semibold">To be refunded</dt>
                    <dd className="col-5 text-end fw-semibold text-danger">
                      {formatMoney(order.refund.amount, settings)}
                    </dd>
                  </>
                ) : (
                  <dd className="col-12 text-soft mt-2 mb-0" style={{ fontSize: '.8rem' }}>
                    {refundSent > 0
                      ? 'Cancelled and settled — nothing is owed either way.'
                      : 'Cancelled. Nothing was charged.'}
                  </dd>
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

          {/*
            * Collection and delivery are different promises, so they are not
            * the same panel with a different heading. One says where we are
            * bringing the goat; the other says where to come and when you said
            * you would -- which is what the buyer will want in front of them
            * on the morning, at the gate, on a phone.
            */}
          {order.pickup ? (
            <div className="panel">
              <h2 className="h6 mb-3">Collecting your goat</h2>

              <p className="mb-2">
                <strong className="text-ink">{formatDateTime(order.pickup.at)}</strong>
              </p>

              <address className="mb-0 small">
                {order.pickup.address}
                {order.pickup.phone && <><br />{order.pickup.phone}</>}
              </address>

              {order.pickup.instructions && (
                <p className="small text-soft mt-2 mb-0">{order.pickup.instructions}</p>
              )}

              <p className="small text-soft mt-2 mb-0">
                Bring this order number. We will have the goat penned and ready.
              </p>

              {/*
                * For anyone who has travelled far enough that getting home the
                * same day is a real question. Recommendations the farm typed
                * in, nothing more -- no room is held here and no money for one
                * passes through this shop.
                */}
              {/* The same cards the buyer saw at checkout, so what they were
                  shown before paying is still there on the day. */}
              {order.shipping.notes && (
                <p className="small text-soft mt-2 mb-0"><em>{order.shipping.notes}</em></p>
              )}
            </div>
          ) : (
            <div className="panel">
              <h2 className="h6 mb-3">Delivery address</h2>
              <address className="mb-0 small">
                <strong>{order.customer.name}</strong><br />
                {order.customer.phone}<br />
                {order.shipping.address_line}<br />
                {order.shipping.area && <>{order.shipping.area}<br /></>}
                {order.shipping.city} {order.shipping.postal_code}
              </address>
              {order.shipping.zone && (
                <p className="small text-soft mt-2 mb-0">
                  <i className="bi bi-geo-alt me-1" aria-hidden="true" />
                  {order.shipping.zone}
                </p>
              )}

              {order.shipping.notes && (
                <p className="small text-soft mt-2 mb-0"><em>{order.shipping.notes}</em></p>
              )}
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}
