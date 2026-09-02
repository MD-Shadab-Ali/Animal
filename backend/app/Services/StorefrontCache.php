<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the storefront to forget what it knows.
 *
 * The shop is a separate Next.js app that caches what this API returns. Left to
 * itself it re-asks on a timer, so an edit made in Filament could sit behind a
 * stale page for the length of that timer -- and the first visitor afterwards
 * still saw the old copy while the new one was built behind them.
 *
 * This closes that gap: save a goat, and the pages that mention it are dropped
 * straight away.
 */
class StorefrontCache
{
    /** Everything whose content changes when a listing or its animals change. */
    public const GOATS = 'goats';

    /**
     * The homestay pages -- the room list, and every room's own page.
     *
     * Dropped for two different reasons, which is why one tag covers both. A
     * room edited in the admin changes what its page says; a booking taken or
     * cancelled changes which nights that page shows as free. The second is the
     * one that matters: a calendar cached for a minute is a calendar offering a
     * room somebody else has already taken.
     */
    public const ROOMS = 'rooms';

    /**
     * @param  array<int, string>  $tags
     */
    public function purge(array $tags): void
    {
        $secret = config('services.storefront.revalidate_secret');
        $url    = rtrim((string) config('services.storefront.url'), '/');

        // Unconfigured means the storefront is not listening, which is the
        // normal state in tests and in a backend-only deployment.
        if (blank($secret) || blank($url)) {
            return;
        }

        try {
            $request = Http::timeout(3)->withHeaders(['X-Revalidate-Secret' => $secret]);

            if ($bundle = config('services.recaptcha.ca_bundle')) {
                $request = $request->withOptions(['verify' => $bundle]);
            }

            $request->post($url.'/api/revalidate', ['tags' => $tags]);
        } catch (Throwable $e) {
            /*
             * Never let this break the save that triggered it. An admin who
             * corrects a price has done their job; the shop catching up a
             * minute later on its own timer is a far smaller problem than the
             * edit failing.
             */
            Log::warning('Storefront revalidation failed', [
                'tags'  => $tags,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
