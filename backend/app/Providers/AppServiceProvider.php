<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use App\Observers\OrderObserver;
use App\Observers\UserObserver;
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
        User::observe(UserObserver::class);

        $this->registerRateLimiters();

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

        // Placing an order — generous for a person, tight for a script.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
