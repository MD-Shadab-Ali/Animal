'use client';

import { useEffect } from 'react';
import { Toaster } from 'react-hot-toast';
import { AuthProvider } from '@/context/AuthContext';
import { CartProvider } from '@/context/CartContext';
import { SellerProvider } from '@/context/SellerContext';
import { SiteProvider } from '@/context/SiteContext';

export default function Providers({ site, children }) {
  // Bootstrap's JS powers the dropdowns, offcanvas, tabs and collapse.
  useEffect(() => {
    import('bootstrap/dist/js/bootstrap.bundle.min.js');
  }, []);

  return (
    <SiteProvider value={site}>
      <AuthProvider>
        <CartProvider>
          <SellerProvider>
            {children}

            <Toaster
              position="top-center"
              toastOptions={{
                style: { borderRadius: '14px', fontSize: '.92rem' },
                success: { iconTheme: { primary: 'var(--brand-primary)', secondary: '#fff' } },
              }}
            />
          </SellerProvider>
        </CartProvider>
      </AuthProvider>
    </SiteProvider>
  );
}
