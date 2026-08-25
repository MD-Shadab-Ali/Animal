<?php

namespace App\Filament\Widgets;

use App\Models\ContactMessage;
use App\Models\Goat;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->canManage('sales') ?? false;
    }

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $symbol = Setting::currencySymbol();

        $revenue = Order::whereNot('status', 'cancelled')->sum('total');
        $monthRevenue = Order::whereNot('status', 'cancelled')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total');

        $pending = Order::where('status', 'pending')->count();
        $inStock = Goat::published()->inStock()->count();
        $unread  = ContactMessage::where('is_read', false)->count();

        return [
            Stat::make('Revenue', $symbol.number_format((float) $revenue))
                ->description($symbol.number_format((float) $monthRevenue).' this month')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Orders', Order::count())
                ->description($pending.' waiting to be confirmed')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pending > 0 ? 'warning' : 'gray'),

            Stat::make('Goats in the shop', $inStock)
                ->description(Goat::where('status', 'sold')->count().' sold')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('primary'),

            Stat::make('Customers', User::where('role', 'customer')->count())
                ->description($unread > 0 ? $unread.' unread messages' : 'Inbox clear')
                ->descriptionIcon('heroicon-m-users')
                ->color($unread > 0 ? 'warning' : 'gray'),
        ];
    }
}
