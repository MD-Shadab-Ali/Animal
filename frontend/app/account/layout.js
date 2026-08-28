import AccountNav from '@/components/account/AccountNav';
import RequireAuth from '@/components/account/RequireAuth';
import StaffNotice from '@/components/account/StaffNotice';

export const metadata = { title: 'My account' };

export default function AccountLayout({ children }) {
  return (
    <div className="section">
      <div className="container">
        <RequireAuth>
          <StaffNotice />

          <div className="row g-4">
            <div className="col-lg-3">
              <AccountNav />
            </div>
            <div className="col-lg-9">{children}</div>
          </div>
        </RequireAuth>
      </div>
    </div>
  );
}
