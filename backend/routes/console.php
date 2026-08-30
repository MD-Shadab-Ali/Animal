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
