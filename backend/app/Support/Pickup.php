<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * When a buyer may come and collect, and what to tell them when they do.
 *
 * The farm decides these hours, not the code, so every one of them is a
 * setting. The point of the whole arrangement is that nobody turns up at a
 * gate at dusk with an animal and no way home: a slot is agreed in advance,
 * and the checks below are what stop one being agreed at three in the morning.
 */
class Pickup
{
    public static function opensAt(): string
    {
        return self::time(Setting::get('pickup_opens_at'), '07:00');
    }

    public static function closesAt(): string
    {
        return self::time(Setting::get('pickup_closes_at'), '18:00');
    }

    /**
     * Days of notice before the earliest bookable slot.
     *
     * The goat has to be picked out of the pen, checked and tagged before
     * anybody arrives for it, which is not something to be done while a buyer
     * waits at the gate.
     */
    public static function leadDays(): int
    {
        return max(0, (int) Setting::get('pickup_lead_days', 1));
    }

    public static function earliestDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays(self::leadDays());
    }

    /**
     * How far ahead a booking is still a plan rather than a guess.
     *
     * A setting, because the farm knows its own year: buyers plan months out
     * for a festival and a fortnight out for a Tuesday.
     */
    public static function horizonDays(): int
    {
        return max(1, (int) Setting::get('pickup_horizon_days', 60));
    }

    public static function latestDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays(self::horizonDays());
    }

    /**
     * The times of day on offer, on the hour.
     *
     * On the hour rather than to the minute because this is an appointment at
     * a farm, not a train: "come at ten" is what will actually be said on the
     * phone, and offering 10:35 would only invite a precision nobody keeps.
     *
     * @return list<string>
     */
    public static function slots(): array
    {
        $open = (int) explode(':', self::opensAt())[0];
        $close = (int) explode(':', self::closesAt())[0];

        if ($close < $open) {
            return [self::opensAt()];
        }

        $slots = [];

        for ($hour = $open; $hour <= $close; $hour++) {
            $slots[] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00';
        }

        return $slots;
    }

    /**
     * Whether a chosen moment is one we actually offered.
     *
     * Checked on the server as well as the browser, because the browser is
     * where a buyer picks a time and not where the farm has to honour it.
     */
    public static function isBookable(?CarbonImmutable $when): bool
    {
        return self::problemWith($when) === null;
    }

    /**
     * Why this moment cannot be booked, in words for the person who chose it.
     *
     * Three different things can be wrong and they are not interchangeable. A
     * buyer who picked a date months out was being told to choose one of the
     * times on offer, which sent them to fiddle with the hour while the date
     * sat there being the actual problem. Each reason names itself, and names
     * the edge it fell outside, so the next attempt can succeed.
     */
    public static function problemWith(?CarbonImmutable $when): ?string
    {
        if ($when === null) {
            return 'Please choose the day and time you will come.';
        }

        if ($when->lt(self::earliestDate()->startOfDay())) {
            return 'We need time to have the goat ready, so the earliest is '
                .self::earliestDate()->format('j M Y').'.';
        }

        if ($when->gt(self::latestDate()->endOfDay())) {
            return 'We are only taking collections up to '
                .self::latestDate()->format('j M Y').' for now. Call us to arrange a later date.';
        }

        if (! in_array($when->format('H:i'), self::slots(), true)) {
            return 'We hand goats over on the hour, between '
                .self::opensAt().' and '.self::closesAt().'.';
        }

        return null;
    }

    /*
     * Somewhere to stay used to live here, as a list of other people's guest
     * houses with a phone number each. The farm has rooms of its own now and
     * can actually hold one, so that list is gone and App\Support\Homestay
     * answers the question instead -- with a bed at the end of it rather than
     * a recommendation.
     */

    public static function instructions(): ?string
    {
        $instructions = trim((string) Setting::get('pickup_instructions', ''));

        return $instructions !== '' ? $instructions : null;
    }

    /** Everything the checkout needs to draw a slot picker. */
    public static function config(): array
    {
        return [
            'earliest_date' => self::earliestDate()->toDateString(),
            'latest_date' => self::latestDate()->toDateString(),
            'slots' => self::slots(),
            'lead_days' => self::leadDays(),
            'address' => Setting::get('contact_address'),
            'phone' => Setting::get('contact_phone'),
            'instructions' => self::instructions(),
        ];
    }

    /** A stored time, or the fallback when the setting is missing or malformed. */
    private static function time(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
    }
}
