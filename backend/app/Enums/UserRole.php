<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Staff = 'staff';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Admin    => 'Administrator',
            self::Manager  => 'Manager',
            self::Staff    => 'Staff',
            self::Customer => 'Customer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin    => 'Full access, including settings, staff accounts and payment configuration.',
            self::Manager  => 'Runs the shop: catalog, orders, customers, content and the blog. No settings or staff accounts.',
            self::Staff    => 'Day-to-day order handling and the message inbox only.',
            self::Customer => 'Shops on the storefront. No admin access.',
        };
    }

    /** Which parts of the admin panel this role may open. */
    public function areas(): array
    {
        return match ($this) {
            self::Admin => ['catalog', 'sales', 'customers', 'marketplace', 'content', 'blog', 'inbox', 'configuration'],
            self::Manager => ['catalog', 'sales', 'customers', 'marketplace', 'content', 'blog', 'inbox'],
            self::Staff => ['sales', 'inbox'],
            self::Customer => [],
        };
    }

    public static function staffRoles(): array
    {
        return [self::Admin, self::Manager, self::Staff];
    }

    /** Options for a Filament select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
