<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * When a room can be booked, and what to tell a guest about staying in it.
 *
 * The farm sets all of this, not the code -- the same arrangement Pickup makes
 * for collection hours, and for the same reason. What time a room is ready and
 * how far ahead the calendar runs are answers that change with the season and
 * the staff, and a developer is the wrong person to be asking.
 */
class Homestay
{
    /** Rooms are on offer at all. */
    public static function isEnabled(): bool
    {
        return (bool) Setting::get('homestay_enabled', true);
    }

    public static function checkInTime(): string
    {
        return self::time(Setting::get('checkin_time'), '14:00');
    }

    public static function checkOutTime(): string
    {
        return self::time(Setting::get('checkout_time'), '11:00');
    }

    /**
     * Days of notice before the earliest bookable night.
     *
     * A room has to be made up, and that is not something to be done while
     * somebody stands in the yard holding a bag.
     */
    public static function leadDays(): int
    {
        return max(0, (int) Setting::get('booking_lead_days', 1));
    }

    public static function earliestDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays(self::leadDays());
    }

    /**
     * How far ahead a booking is a plan rather than a guess.
     *
     * A setting, because the farm knows its own year: guests plan months out
     * for a festival and a fortnight out for a Tuesday.
     */
    public static function horizonDays(): int
    {
        return max(1, (int) Setting::get('booking_horizon_days', 180));
    }

    public static function latestDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays(self::horizonDays());
    }

    public static function houseRules(): ?string
    {
        $rules = trim((string) Setting::get('homestay_house_rules', ''));

        return $rules !== '' ? $rules : null;
    }

    public static function cancellationNote(): ?string
    {
        $note = trim((string) Setting::get('homestay_cancellation_note', ''));

        return $note !== '' ? $note : null;
    }

    public static function intro(): ?string
    {
        $intro = trim((string) Setting::get('homestay_intro', ''));

        return $intro !== '' ? $intro : null;
    }

    /** Everything the storefront needs to draw a date picker and a room page. */
    public static function config(): array
    {
        return [
            'enabled'           => self::isEnabled(),
            'intro'             => self::intro(),
            'earliest_date'     => self::earliestDate()->toDateString(),
            'latest_date'       => self::latestDate()->toDateString(),
            'lead_days'         => self::leadDays(),
            'check_in_time'     => self::checkInTime(),
            'check_out_time'    => self::checkOutTime(),
            'house_rules'       => self::houseRules(),
            'cancellation_note' => self::cancellationNote(),
        ];
    }

    /** A stored time, or the fallback when the setting is missing or malformed. */
    private static function time(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
    }
}
