'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { useAuth } from '@/context/AuthContext';
import { useSeller } from '@/context/SellerContext';
import { useSettings } from '@/context/SiteContext';
import { ADMIN_URL } from '@/lib/admin';
import SellerApplicationForm from '@/components/seller/SellerApplicationForm';

const STEPS = [
  ['bi-person-plus', 'Apply once', 'Tell us who you are and where your farm is. We verify every seller before they can list.'],
  ['bi-clipboard-check', 'List your goats', 'Add breed, weight, age and photos. Our team checks each listing before it goes live.'],
  ['bi-truck', 'We handle delivery', 'Buyers pay up front or on delivery. We arrange transport and collect the money.'],
  ['bi-cash-coin', 'Get paid', 'Once an order is delivered your earnings are settled and paid out.'],
];

export default function SellPage() {
  const router = useRouter();
  const settings = useSettings();
  const { isAuthenticated, isStaff } = useAuth();
  const { seller, loading } = useSeller();

  // Applicants and approved sellers go straight through — the seller area already
  // explains every status, so there is no reason to stop them here first.
  useEffect(() => {
    if (!loading && seller) router.replace('/seller');
  }, [loading, seller, router]);

  // Avoid flashing the pitch at someone who is about to be redirected.
  if (loading || seller) {
    return (
      <div className="section">
        <div className="container text-center py-5">
          <span className="spinner-border text-brand" role="status" />
          <p className="text-soft mt-3 mb-0">
            {seller ? 'Taking you to your seller dashboard…' : 'Loading…'}
          </p>
        </div>
      </div>
    );
  }

  return (
    <>
      <section className="hero">
        <div className="container hero__inner">
          <div className="col-lg-7">
            <span className="eyebrow d-block mb-2">Sell with us</span>
            <h1 className="hero__title">Turn your herd into income</h1>
            <p className="hero__lead mb-4">
              List your goats alongside ours. We verify every seller, check every listing,
              handle delivery, and pay you after the sale.
            </p>

            <div className="d-flex flex-wrap gap-2">
              <span className="badge-verified">
                <i className="bi bi-patch-check-fill" aria-hidden="true" /> Verified sellers only
              </span>
              <span className="chip">{settings.default_commission_rate || 10}% commission</span>
              <span className="chip">No listing fee</span>
            </div>
          </div>
        </div>
      </section>

      <section className="section">
        <div className="container">
          <div className="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-5">
            {STEPS.map(([icon, title, text], index) => (
              <div className="col" key={title}>
                <div className="step panel h-100">
                  <span className="step__icon"><i className={`bi ${icon}`} aria-hidden="true" /></span>
                  <h3>{index + 1}. {title}</h3>
                  <p>{text}</p>
                </div>
              </div>
            ))}
          </div>

          <div className="col-lg-8 mx-auto">
            {!isAuthenticated && (
              <div className="panel text-center">
                <h2 className="h5 mb-2">Sign in to apply</h2>
                <p className="text-soft">You need an account before you can sell. It takes a minute.</p>
                <div className="d-flex gap-2 justify-content-center">
                  <Link href="/login" className="btn btn-brand px-4">Sign in</Link>
                  <Link href="/register" className="btn btn-quiet px-4">Create account</Link>
                </div>
              </div>
            )}

            {/* Staff approve these applications and settle the payouts that
                follow, so they cannot be on the other side of one. Their own
                stock is listed in the panel instead. Said here rather than
                after a long form and a rejected submit. */}
            {isAuthenticated && isStaff && (
              <div className="panel text-center">
                <h2 className="h5 mb-2">Staff accounts do not apply here</h2>
                <p className="text-soft mb-3">
                  You review seller applications, so you cannot submit one. List the
                  farm&apos;s own goats from the admin panel — they sell alongside
                  seller listings.
                </p>
                <a
                  className="btn btn-brand px-4"
                  href={ADMIN_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Open admin panel
                </a>
              </div>
            )}

            {isAuthenticated && !isStaff && <SellerApplicationForm />}
          </div>
        </div>
      </section>
    </>
  );
}
