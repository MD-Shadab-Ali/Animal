import { apiFetch } from '@/lib/api';

/** Methods the provider confirms for us. Mirrors PaymentMethod::GATEWAY_CODES. */
export const GATEWAY_CODES = ['esewa', 'khalti'];

export function isGatewayMethod(code) {
  return GATEWAY_CODES.includes(code);
}

/**
 * Hand the buyer over to the payment provider.
 *
 * The two want to be entered differently -- Khalti gives us a link, eSewa
 * insists on a signed form POST -- so the server says which, and this does as
 * it is told rather than keeping its own opinion about each provider.
 *
 * How much to charge is deliberately not sent: the order knows what is due
 * today under the plan it was placed on, and money worked out in a browser is
 * money that can be argued with.
 */
export async function payThroughGateway(orderNumber, gateway, token) {
  return startPayment(`/orders/${orderNumber}/pay/${gateway}`, token);
}

/**
 * The same handover, for a room.
 *
 * Nothing below this point knows the difference between a goat and a bed --
 * the provider is opened, the browser leaves, and the server settles whatever
 * the attempt was against when it comes back.
 */
export async function payForBooking(bookingNumber, gateway, token) {
  return startPayment(`/bookings/${bookingNumber}/pay/${gateway}`, token);
}

async function startPayment(path, token) {
  const response = await apiFetch(path, {
    method: 'POST',
    token,
    body: {},
  });

  const start = response.data;

  // An earlier attempt turned out to have gone through, so there is nothing
  // left to pay and nowhere to send them.
  if (start.type === 'settled') {
    return { settled: true };
  }

  if (start.type === 'redirect') {
    window.location.href = start.url;
    return { settled: false };
  }

  if (start.type === 'form') {
    submitHiddenForm(start.url, start.fields);
    return { settled: false };
  }

  throw new Error('That payment could not be started.');
}

/**
 * A real form POST, because eSewa will not accept anything else.
 *
 * It has to be in the document to submit, and the page is leaving anyway, so
 * there is nothing to tidy up afterwards.
 */
function submitHiddenForm(action, fields) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = action;
  form.style.display = 'none';

  Object.entries(fields || {}).forEach(([name, value]) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  });

  document.body.appendChild(form);
  form.submit();
}
