'use client';

import Link from 'next/link';
import { useCart } from '@/context/CartContext';
import { formatMoney } from '@/lib/format';

export default function CartLine({ item, isFirst, settings }) {
  const { updateItem, removeItem, loading } = useCart();

  return (
    <div className={`d-flex gap-3 p-3 ${isFirst ? '' : 'border-top'}`}>
      <Link href={`/goats/${item.goat.slug}`} style={{ width: 96, flexShrink: 0 }}>
        <div className="gallery__thumb" style={{ aspectRatio: '4 / 3' }}>
          {item.goat.thumbnail
            ? <img src={item.goat.thumbnail} alt={item.goat.name} />
            : <div className="card-goat__empty"><i className="bi bi-image" /></div>}
        </div>
      </Link>

      <div className="flex-grow-1">
        <Link href={`/goats/${item.goat.slug}`} className="fw-semibold text-body d-block">
          {item.goat.name}
        </Link>

        <div className="small text-soft mb-2">
          {item.goat.breed}
          {item.goat.weight_kg ? ` · ${item.goat.weight_kg} kg` : ''}
        </div>

        <div className="d-flex flex-wrap align-items-center gap-3">
          <div className="input-group input-group-sm" style={{ width: 120 }}>
            <button
              className="btn btn-outline-secondary"
              onClick={() => updateItem(item.id, item.quantity - 1)}
              disabled={loading || item.quantity <= 1}
            >
              <i className="bi bi-dash" />
            </button>
            <span className="form-control text-center bg-white">{item.quantity}</span>
            <button
              className="btn btn-outline-secondary"
              onClick={() => updateItem(item.id, item.quantity + 1)}
              disabled={loading}
            >
              <i className="bi bi-plus" />
            </button>
          </div>

          <button
            className="btn btn-link btn-sm text-danger p-0 text-decoration-none"
            onClick={() => removeItem(item.id)}
            disabled={loading}
          >
            <i className="bi bi-trash me-1" />Remove
          </button>
        </div>
      </div>

      <div className="text-end">
        <div className="fw-bold">{formatMoney(item.line_total, settings)}</div>
        {item.quantity > 1 && (
          <div className="small text-soft">{formatMoney(item.unit_price, settings)} each</div>
        )}
      </div>
    </div>
  );
}
