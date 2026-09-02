<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type', 'label', 'hint', 'sort_order'];

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    /**
     * Drop the cached map.
     *
     * Public because the model events above only fire for writes that go
     * through Eloquent. A migration inserting settings with the query builder
     * -- the normal way to write one -- leaves a map cached for ever that has
     * never heard of the new keys, so every one of them silently reads as its
     * code default. Nothing looks broken; the values are simply ignored.
     */
    public static function flushCache(): void
    {
        Cache::forget('settings.all');
    }

    /** All settings as a flat key => casted value map. */
    public static function all_values(): array
    {
        return Cache::rememberForever('settings.all', function () {
            return static::query()->get()->mapWithKeys(fn (self $s) => [
                $s->key => $s->castedValue(),
            ])->all();
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_values()[$key] ?? $default;
    }

    /*
     * One place for currency, so no screen can drift onto a hardcoded symbol.
     * The values live in Site settings; these are only the last-resort fallbacks
     * used before the settings table is seeded.
     */
    public const FALLBACK_CURRENCY_CODE = 'NPR';

    public const FALLBACK_CURRENCY_SYMBOL = 'रु';

    public static function currencyCode(): string
    {
        return static::get('currency_code') ?: self::FALLBACK_CURRENCY_CODE;
    }

    public static function currencySymbol(): string
    {
        return static::get('currency_symbol') ?: self::FALLBACK_CURRENCY_SYMBOL;
    }

    /** Locale used to group digits. Nepal uses lakh grouping, as en-IN does. */
    public static function numberLocale(): string
    {
        return static::get('number_locale') ?: 'en-IN';
    }

    public function castedValue(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($this->value) ? $this->value + 0 : null,
            'json' => json_decode((string) $this->value, true),
            'image' => $this->value ? asset('storage/'.$this->value) : null,
            default => $this->value,
        };
    }
}
