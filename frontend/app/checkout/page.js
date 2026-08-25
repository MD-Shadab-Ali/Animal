'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import CartSummary from '@/components/cart/CartSummary';
import CheckoutFields from '@/components/checkout/CheckoutFields';
import { useAuth } from '@/context/AuthContext';
import { useCart } from '@/context/CartContext';
import { useSettings } from '@/context/SiteContext';
import { apiFetch } from '@/lib/api';
import { formatMoney } from '@/lib/format';

export default function CheckoutPage() {
  const router = useRouter();
  const settings = useSettings();
  const { user, token, isAuthenticated, loading: authLoading } = useAuth();
  const { cart, refresh } = useCart();

  const [options, setOptions] = useState(null);
  const [addresses, setAddresses] = useState([]);
  const [errors, setErrors] = useState({});
  const [placing, setPlacing] = useState(false);

  const [form, setForm] = useState({
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    address_line: '',
    area: '',
    city: '',
    postal_code: '',
    order_notes: '',
    delivery_zone_id: '',
    payment_method: '',
    payment_plan: '',
    save_address: true,
  });

  // Declared before the effect below, which calls it once addresses load.
  const applyAddress = useCallback((address) => {
    setForm((current) => ({
      ...current,
      customer_name: address.full_name,
      customer_phone: address.phone,
      address_line: address.address_line,
      area: address.area || '',
      city: address.city,
      postal_code: address.postal_code || '',
      save_address: false,
    }));
  }, []);

  // Pull the delivery zones and payment methods the admin has enabled.
  useEffect(() => {
    if (!token) return;

    Promise.all([
      apiFetch('/checkout/options', { token }),
      apiFetch('/addresses', { token }),
    ])
      .then(([optionsResponse, addressResponse]) => {
        const data = optionsResponse.data;
        setOptions(data);
        setAddresses(addressResponse.data || []);

        setForm((current) => ({
          ...current,
          customer_name: current.customer_name || user?.name || '',
          customer_phone: current.customer_phone || user?.phone || '',
          customer_email: current.customer_email || user?.email || '',
          delivery_zone_id: current.delivery_zone_id || data.delivery_zones?.[0]?.id || '',
          // Default to the first method that can actually place an order —
          // cash on delivery sits at the top of the list but only settles it.
          payment_method: current.payment_method
            || (data.payment_methods || []).find((method) => method.selectable !== false)?.code
            || '',
        }));

        const preferred = (addressResponse.data || []).find((address) => address.is_default);
        if (preferred) applyAddress(preferred);
      })
      .catch(() => toast.error('Could not load checkout options.'));
  }, [token, user, applyAddress]);

  const update = (key) => (event) => {
    const value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    setForm((current) => ({ ...current, [key]: value }));
  };

  const selectedZone = options?.delivery_zones?.find(
    (zone) => String(zone.id) === String(form.delivery_zone_id)
  );

  const subtotalAfterDiscount = Number(cart?.totals?.total || 0);

  const deliveryCharge = selectedZone
    ? (selectedZone.free_above !== null && subtotalAfterDiscount >= selectedZone.free_above ? 0 : selectedZone.charge)
    : null;

  const chosenMethod = options?.payment_methods?.find(
    (method) => method.code === form.payment_method
  );

  // Derived, not synced: if the chosen method turns out to be delivery-only
  // (an admin can flip that at any time) fall back to the first usable one.
  const selectedMethod = chosenMethod?.selectable === false
    ? options?.payment_methods?.find((method) => method.selectable !== false)
    : chosenMethod;

  // What the order will come to, which is what an advance is a share of.
  const orderTotal = subtotalAfterDiscount + Number(deliveryCharge || 0);

  // A plan only means something for the method it belongs to. Derive it rather
  // than syncing state, so switching method can never leave a stale choice.
  const plans = selectedMethod?.plans || [];
  const paymentPlan = plans.includes(form.payment_plan) ? form.payment_plan : (plans[0] || '');

  const submit = async (event) => {
    event.preventDefault();
    setPlacing(true);
    setErrors({});

    try {
      const response = await apiFetch('/checkout', {
        method: 'POST',
        token,
        body: {
          ...form,
          payment_method: selectedMethod?.code || form.payment_method,
          payment_plan: paymentPlan,
        },
      });
      await refresh();
      toast.success(response.message);
      router.push(`/account/orders/${response.data.order_number}?placed=1`);
    } catch (error) {
      setErrors(error.errors || {});
      toast.error(error.errors?.cart?.[0] || error.message || 'Could not place your order.');
    } finally {
      setPlacing(false);
    }
  };

  if (authLoading) {
    return <div className="section"><div className="container text-center"><span className="spinner-border text-brand" /></div></div>;
  }

  if (!isAuthenticated) {
    return (
      <div className="section">
        <div className="container">
          <div className="empty">
            <i className="bi bi-person-lock" />
            <h1 className="h4">Sign in to check out</h1>
            <p>You need an account so we can send you order updates.</p>
            <Link href="/login" className="btn btn-brand px-4">Sign in</Link>
          </div>
        </div>
      </div>
    );
  }

  if (!cart?.items?.length) {
    return (
      <div className="section">
        <div className="container">
          <div className="empty">
            <i className="bi bi-bag" />
            <h1 className="h4">Nothing to check out</h1>
            <Link href="/shop" className="btn btn-brand px-4">Browse goats</Link>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="section">
      <div className="container">
        <h1 className="section-title mb-4">Checkout</h1>

        <form onSubmit={submit}>
          <div className="row g-4">
            <div className="col-lg-8">
              <CheckoutFields
                form={form}
                errors={errors}
                update={update}
                addresses={addresses}
                applyAddress={applyAddress}
                options={options}
                settings={settings}
                selectedZone={selectedZone}
                selectedMethod={selectedMethod}
                subtotalAfterDiscount={subtotalAfterDiscount}
                orderTotal={orderTotal}
                paymentPlan={paymentPlan}
              />
            </div>

            <div className="col-lg-4">
              <CartSummary deliveryCharge={deliveryCharge}>
                <ul className="list-unstyled small text-soft mb-3">
                  {cart.items.map((item) => (
                    <li key={item.id} className="d-flex justify-content-between gap-2">
                      <span className="text-truncate">{item.goat.name} × {item.quantity}</span>
                      <span>{formatMoney(item.line_total, settings)}</span>
                    </li>
                  ))}
                </ul>
              </CartSummary>

              <button className="btn btn-brand btn-lg w-100 mt-3" type="submit" disabled={placing}>
                {placing ? 'Placing order…' : 'Place order'}
              </button>

              <p className="small text-soft text-center mt-2 mb-0">
                {paymentPlan === 'on_delivery' || !paymentPlan
                  ? 'Nothing is charged online. Payment is collected on delivery.'
                  : 'We will show you where to send the money as soon as the order is placed.'}
              </p>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
}
