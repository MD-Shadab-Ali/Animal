<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Goat;
use App\Models\GoatWeight;
use App\Models\Order;
use App\Models\Room;
use App\Models\User;
use App\Observers\BookingObserver;
use App\Observers\OrderObserver;
use App\Observers\UserObserver;
use App\Services\StorefrontCache;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Order::observe(OrderObserver::class);

        // The booking observer is what keeps `booking_nights` telling the truth
        // no matter who writes to a booking -- the storefront, the admin panel,
        // or a payment landing. See App\Observers\BookingObserver.
        Booking::observe(BookingObserver::class);

        User::observe(UserObserver::class);

        $this->registerRateLimiters();
        $this->purgeStorefrontOnCatalogChange();

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
    }

    private function registerRateLimiters(): void
    {
        // General browsing and account activity.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(90)
            ->by($request->user()?->id ?: $request->ip()));

        // Credential endpoints: strict, keyed on both the email and the IP so one
        // attacker cannot lock out a real customer by guessing their address.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('auth-email:'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
        ]);

        // Public write endpoints that anyone can hit without an account.
        RateLimiter::for('public-forms', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        // Google sign-in. Keyed on the IP alone: the request carries an ID token
        // and no email, so there is nothing else to key on until it is verified.
        RateLimiter::for('google-auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Placing an order — generous for a person, tight for a script.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Drop the storefront's cached pages whenever the catalogue moves.
     *
     * Both models matter: a listing carries the price and the name, and the
     * animals behind it carry the weights and ages the cards and the detail
     * page now describe. Editing either changes what a visitor should see.
     */
    private function purgeStorefrontOnCatalogChange(): void
    {
        $watched = [
            Goat::class => StorefrontCache::GOATS,
            GoatWeight::class => StorefrontCache::GOATS,

            // A room's rate, photographs and description live on its own page.
            // The nights it has free are the other half of that page and do not
            // change when the room is saved -- BookingService purges those as
            // it writes them, which is the only moment they move.
            Room::class => StorefrontCache::ROOMS,
        ];

        foreach ($watched as $model => $tag) {
            foreach (['saved', 'deleted'] as $event) {
                $model::{$event}(fn () => app(StorefrontCache::class)->purge([$tag]));
            }
        }
    }
}
