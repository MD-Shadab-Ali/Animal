'use client';

import { useEffect } from 'react';

/**
 * Keep a page honest about data that changes somewhere else.
 *
 * These pages fetch once when they mount and then hold that copy for as long
 * as the tab stays open. That is fine for things the buyer changes themselves,
 * and wrong for anything staff change: an order sitting open in one tab while
 * its status moves in the admin panel goes on claiming the old one until
 * somebody reloads.
 *
 * Coming back to the tab is the moment that matters, because it is when the
 * page gets read again -- so that is the main trigger. The interval is a
 * second net for a page left open and watched, and it only runs while the tab
 * is actually in front of someone.
 *
 * `refresh` must be stable (wrap it in useCallback), or every render tears the
 * listeners down and puts them back.
 */
export function useLiveRefresh(refresh, { enabled = true, intervalMs = 30000, immediate = false } = {}) {
  /*
   * The first fetch, when the caller wants this hook to own it.
   *
   * Separate from the subscription below because they switch off for
   * different reasons: a delivered order has no further changes to watch for,
   * but it still has to be loaded once to be read at all.
   */
  useEffect(() => {
    if (immediate) {
      refresh();
    }
  }, [refresh, immediate]);

  useEffect(() => {
    if (! enabled) {
      return undefined;
    }

    // Switching tabs fires visibilitychange and focus together, and the
    // interval can land on top of a slow request. One at a time.
    let inFlight = false;

    const run = async () => {
      if (inFlight || document.visibilityState !== 'visible') {
        return;
      }

      inFlight = true;

      try {
        await refresh();
      } catch {
        /*
         * Nobody asked for this fetch, so nobody should be told it failed.
         * The page keeps the data it already had and tries again next time --
         * and an unhandled rejection here would surface as a console error on
         * a page the reader never touched.
         */
      } finally {
        inFlight = false;
      }
    };

    document.addEventListener('visibilitychange', run);
    window.addEventListener('focus', run);

    const timer = intervalMs ? setInterval(run, intervalMs) : null;

    return () => {
      document.removeEventListener('visibilitychange', run);
      window.removeEventListener('focus', run);

      if (timer) {
        clearInterval(timer);
      }
    };
  }, [refresh, enabled, intervalMs]);
}
