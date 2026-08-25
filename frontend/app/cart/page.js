'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useAuth } from '@/context/AuthContext';
import { useCart } from '@/context/CartContext';
import { useSettings } from '@/context/SiteContext';
import CartSummary from '@/components/cart/CartSummary';
import CartLine from '@/components/cart/CartLine';

export default function CartPage() {
  const { isAuthenticated, loading: authLoading } = useAuth();
  const { cart, loading, clearCart } = useCart();
  const settings = useSettings();

  if (authLoading) {
    return (
      <div className="section">
        <div className="container text-center"><span className="spinner-border text-brand" /></div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return (
      <div className="section">
        <div className="container">
          <div className="empty">
            <i className="bi bi-person-lock" />
            <h1 className="h4">Sign in to see your cart</h1>
            <p>Your cart is saved to your account, so it follows you between devices.</p>
            <Link href="/login" className="btn btn-brand px-4">Sign in</Link>
          </div>
        </div>
      </div>
    );
  }

  const items = cart?.items || [];

  if (!items.length) {
    return (
      <div className="section">
        <div className="container">
          <div className="empty">
            <i className="bi bi-bag" />
            <h1 className="h4">Your cart is empty</h1>
            <p>Browse the shop and add a goat to get started.</p>
            <Link href="/shop" className="btn btn-brand px-4">Browse goats</Link>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="section">
      <div className="container">
        <h1 className="section-title mb-4">Your cart</h1>

        <div className="row g-4">
          <div className="col-lg-8">
            <div className="panel p-0 overflow-hidden">
              {items.map((item, index) => (
                <CartLine key={item.id} item={item} isFirst={index === 0} settings={settings} />
              ))}
            </div>

            <div className="d-flex justify-content-between mt-3">
              <Link href="/shop" className="btn btn-link text-decoration-none ps-0">
                <i className="bi bi-arrow-left me-1" />Continue shopping
              </Link>
              <button
                className="btn btn-link text-danger text-decoration-none"
                onClick={clearCart}
                disabled={loading}
              >
                Empty cart
              </button>
            </div>
          </div>

          <div className="col-lg-4">
            <CartSummary showCheckoutButton />
          </div>
        </div>
      </div>
    </div>
  );
}
