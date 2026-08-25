<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Gates a Filament resource behind an area name.
 *
 * A resource declares `protected static string $area = 'catalog';` and this
 * trait keeps it out of the navigation, and off the URL, for roles that are
 * not allowed there.
 */
trait RestrictsAccessByRole
{
    public static function canViewAny(): bool
    {
        return Auth::user()?->canManage(static::area()) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    protected static function area(): string
    {
        return static::$area ?? 'configuration';
    }
}
