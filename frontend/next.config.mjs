/** @type {import('next').NextConfig} */
const nextConfig = {
  images: {
    remotePatterns: [
      { protocol: 'http', hostname: '127.0.0.1', port: '8000', pathname: '/storage/**' },
      { protocol: 'http', hostname: 'localhost', port: '8000', pathname: '/storage/**' },
      // Add your production backend host here when you deploy.
    ],
  },

  async redirects() {
    return [
      /*
       * The orders list lives at /account -- that is what the account nav
       * calls "My orders". But a single order sits at
       * /account/orders/GH-1234, and trimming a URL back to its parent is
       * something people do. Without this it lands on the not-found page,
       * while the same trim on /account/bookings/... arrives somewhere
       * sensible.
       */
      { source: '/account/orders', destination: '/account', permanent: false },
    ];
  },
};

export default nextConfig;
