'use client';

import { createContext, useContext } from 'react';

/**
 * Makes the admin's site settings available to client components
 * (currency formatting, feature toggles, contact details).
 */
const SiteContext = createContext({ settings: {}, menus: {}, footer_pages: [] });

export function SiteProvider({ value, children }) {
  return <SiteContext.Provider value={value}>{children}</SiteContext.Provider>;
}

export function useSite() {
  return useContext(SiteContext);
}

export function useSettings() {
  return useContext(SiteContext).settings || {};
}
