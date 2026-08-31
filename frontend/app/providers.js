'use client';

import { LazyMotion, MotionConfig, domMax } from 'motion/react';
import { useEffect } from 'react';
import { Toaster } from 'react-hot-toast';
import { AuthProvider } from '@/context/AuthContext';
import { CartProvider } from '@/context/CartContext';
import { SellerProvider } from '@/context/SellerContext';
import { SiteProvider } from '@/context/SiteContext';

export default function Providers({ site, children }) {
  // Bootstrap's JS still powers the account dropdown, the tabs and the FAQ
  // collapse. The mobile drawer used to be on this list; it is a Motion
  // drawer now, and owns its own open state.
  useEffect(() => {
    import('bootstrap/dist/js/bootstrap.bundle.min.js');
  }, []);

  return (
    /*
     * LazyMotion loads the animation features as one lazy chunk instead of
     * bundling them into every component that animates, which is why the
     * components below import `m` rather than `motion` -- `m` is the same
     * component with the feature detection left out. domMax rather than
     * domAnimation because the carousel and the photo gallery are dragged,
     * and the filter chips and cart rows animate their layout; the smaller
     * bundle carries neither.
     *
     * reducedMotion="user" is the whole accessibility story for Motion on
     * this site: every transform and layout animation is switched off for
     * anyone whose OS asks for calm, and only opacity is left to fade. It is
     * read here once rather than checked in each component, which is what the
     * hand-rolled matchMedia listeners used to do.
     */
    <LazyMotion features={domMax}>
      <MotionConfig reducedMotion="user">
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
      </MotionConfig>
    </LazyMotion>
  );
}
