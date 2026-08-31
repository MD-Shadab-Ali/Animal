import AccountNav from '@/components/account/AccountNav';

/*
 * The account bar runs across the top, so every page beneath it gets the full
 * width of the container rather than the nine columns a left rail left behind.
 */
export default function AccountDashboardLayout({ children }) {
  return (
    <>
      <AccountNav />
      {children}
    </>
  );
}
