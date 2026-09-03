<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

/*
 * Money that moved without anybody telling us. Frequent because an order that
 * is really paid should not sit unconfirmed for long, and cheap because it
 * only asks about attempts that are actually outstanding.
 */
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();

/*
 * Bookings that are paid for but never got checked in.
 *
 * Daily, because paying in full checks a guest in on the spot -- this is only
 * the net underneath, for stays settled while automatic check-in was switched
 * off. Nothing here is time-of-day sensitive.
 */
Schedule::command('bookings:check-in-arrivals')->dailyAt('01:00')->withoutOverlapping();
