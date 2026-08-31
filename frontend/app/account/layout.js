import RequireAuth from '@/components/account/RequireAuth';
import StaffNotice from '@/components/account/StaffNotice';

export const metadata = { title: 'My account' };

/*
 * Everything under /account needs a signed-in buyer and the staff notice; only
 * the dashboard pages need the account sidebar beside them. That split lives
 * in the (dashboard) group's own layout, which leaves a single order free to
 * use the full width -- the way an order page reads everywhere else.
 */
export default function AccountLayout({ children }) {
  return (
    <div className="section">
      <div className="container">
        <RequireAuth>
          <StaffNotice />
          {children}
        </RequireAuth>
      </div>
    </div>
  );
}
