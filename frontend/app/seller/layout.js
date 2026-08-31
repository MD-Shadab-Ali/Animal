import RequireSeller from '@/components/seller/RequireSeller';
import SellerNav from '@/components/seller/SellerNav';

export const metadata = { title: 'Seller dashboard' };

/*
 * The seller bar runs across the top, so the dashboard beneath it gets the
 * full width of the container rather than the nine columns a left rail left.
 */
export default function SellerLayout({ children }) {
  return (
    <div className="section">
      <div className="container">
        <RequireSeller>
          <SellerNav />
          {children}
        </RequireSeller>
      </div>
    </div>
  );
}
