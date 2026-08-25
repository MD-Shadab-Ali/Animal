'use client';

import { formatMoney } from '@/lib/format';

export default function CheckoutFields({
  form, errors, update, addresses, applyAddress,
  options, settings, selectedZone, selectedMethod, subtotalAfterDiscount,
}) {
  const field = (key, label, { type = 'text', required = false, colClass = 'col-md-6', as = 'input', rows } = {}) => (
    <div className={colClass} key={key}>
      <label className="form-label" htmlFor={key}>
        {label} {required && <span className="text-danger">*</span>}
      </label>
      {as === 'textarea' ? (
        <textarea
          id={key}
          rows={rows || 3}
          className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
          value={form[key]}
          onChange={update(key)}
        />
      ) : (
        <input
          id={key}
          type={type}
          className={`form-control ${errors[key] ? 'is-invalid' : ''}`}
          value={form[key]}
          onChange={update(key)}
          required={required}
        />
      )}
      {errors[key] && <div className="invalid-feedback">{errors[key][0]}</div>}
    </div>
  );

  return (
    <div className="d-grid gap-4">
      {addresses.length > 0 && (
        <section className="panel">
          <h2 className="h6 mb-3">Use a saved address</h2>
          <div className="row g-2">
            {addresses.map((address) => (
              <div className="col-md-6" key={address.id}>
                <button
                  type="button"
                  className="btn btn-outline-secondary w-100 text-start p-3"
                  onClick={() => applyAddress(address)}
                >
                  <strong className="d-block small">
                    {address.label}
                    {address.is_default && <span className="badge text-bg-light border ms-2">Default</span>}
                  </strong>
                  <span className="small text-soft">
                    {address.address_line}, {address.city}
                  </span>
                </button>
              </div>
            ))}
          </div>
        </section>
      )}

      <section className="panel">
        <h2 className="h6 mb-3">Delivery details</h2>
        <div className="row g-3">
          {field('customer_name', 'Full name', { required: true })}
          {field('customer_phone', 'Phone number', { type: 'tel', required: true })}
          {field('customer_email', 'Email', { type: 'email', colClass: 'col-12' })}
          {field('address_line', 'Street address', { required: true, colClass: 'col-12' })}
          {field('area', 'Area / thana')}
          {field('city', 'City / district', { required: true })}
          {field('postal_code', 'Postal code')}
          {field('order_notes', 'Delivery note (optional)', { as: 'textarea', colClass: 'col-12', rows: 2 })}

          <div className="col-12">
            <div className="form-check">
              <input
                className="form-check-input"
                type="checkbox"
                id="save_address"
                checked={form.save_address}
                onChange={update('save_address')}
              />
              <label className="form-check-label" htmlFor="save_address">
                Save this address for next time
              </label>
            </div>
          </div>
        </div>
      </section>

      <section className="panel">
        <h2 className="h6 mb-3">Delivery zone</h2>
        {errors.delivery_zone_id && (
          <div className="alert alert-danger py-2 small">{errors.delivery_zone_id[0]}</div>
        )}

        <div className="d-grid gap-2">
          {(options?.delivery_zones || []).map((zone) => {
            const selected = String(form.delivery_zone_id) === String(zone.id);
            const isFree = zone.free_above !== null && subtotalAfterDiscount >= zone.free_above;

            return (
              <label
                key={zone.id}
                className={`d-flex gap-3 p-3 border rounded ${selected ? 'border-2' : ''}`}
                style={selected ? { borderColor: 'var(--brand-primary)' } : undefined}
              >
                <input
                  type="radio"
                  className="form-check-input mt-1"
                  name="delivery_zone_id"
                  value={zone.id}
                  checked={selected}
                  onChange={update('delivery_zone_id')}
                />
                <span className="flex-grow-1">
                  <strong className="d-block">{zone.name}</strong>
                  {zone.description && <span className="small text-soft d-block">{zone.description}</span>}
                  {zone.estimated_time && <span className="small text-soft">Arrives in {zone.estimated_time}</span>}
                </span>
                <span className="fw-semibold text-nowrap">
                  {isFree ? <span className="text-success">Free</span> : formatMoney(zone.charge, settings)}
                </span>
              </label>
            );
          })}
        </div>

        {selectedZone?.free_above && subtotalAfterDiscount < selectedZone.free_above && (
          <p className="small text-soft mt-2 mb-0">
            Spend {formatMoney(selectedZone.free_above - subtotalAfterDiscount, settings)} more for free delivery in this zone.
          </p>
        )}
      </section>

      <section className="panel">
        <h2 className="h6 mb-3">Payment</h2>
        {errors.payment_method && (
          <div className="alert alert-danger py-2 small">{errors.payment_method[0]}</div>
        )}

        <div className="d-grid gap-2">
          {(options?.payment_methods || []).map((method) => {
            const selected = form.payment_method === method.code;

            return (
              <label
                key={method.code}
                className={`d-flex gap-3 p-3 border rounded ${selected ? 'border-2' : ''}`}
                style={selected ? { borderColor: 'var(--brand-primary)' } : undefined}
              >
                <input
                  type="radio"
                  className="form-check-input mt-1"
                  name="payment_method"
                  value={method.code}
                  checked={selected}
                  onChange={update('payment_method')}
                />
                <span className="flex-grow-1">
                  <strong className="d-block">{method.name}</strong>
                  {method.instructions && <span className="small text-soft">{method.instructions}</span>}
                </span>
                {method.logo && <img src={method.logo} alt={method.name} style={{ height: 24 }} />}
              </label>
            );
          })}
        </div>

        {selectedMethod?.requires_advance && selectedMethod.advance_amount && (
          <div className="alert alert-warning small mt-3 mb-0">
            This method needs an advance of {formatMoney(selectedMethod.advance_amount, settings)} to reserve the goat.
          </div>
        )}
      </section>
    </div>
  );
}
