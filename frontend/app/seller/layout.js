import SellerNav from '@/components/seller/SellerNav';
import RequireSeller from '@/components/seller/RequireSeller';

export const metadata = { title: 'Seller dashboard' };

export default function SellerLayout({ children }) {
  return (
    <div className="section">
      <div className="container">
        <RequireSeller>
          <div className="row g-4">
            <div className="col-lg-3">
              <SellerNav />
            </div>
            <div className="col-lg-9">{children}</div>
          </div>
        </RequireSeller>
      </div>
    </div>
  );
}
