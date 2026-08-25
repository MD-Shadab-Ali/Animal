<?php

namespace App\Filament\Widgets;

use App\Models\OrderItem;
use App\Models\Setting;
use Filament\Widgets\Widget;

class TopSellingGoats extends Widget
{
    protected string $view = 'filament.widgets.top-selling-goats';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->canManage('catalog') ?? false;
    }

    public function getRows(): array
    {
        $symbol = Setting::get('currency_symbol', '');

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->whereNot('orders.status', 'cancelled')
            ->selectRaw('order_items.goat_name, order_items.goat_sku,
                         SUM(order_items.quantity) as sold,
                         SUM(order_items.line_total) as revenue')
            ->groupBy('order_items.goat_name', 'order_items.goat_sku')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'name'    => $row->goat_name,
                'sku'     => $row->goat_sku,
                'sold'    => (int) $row->sold,
                'revenue' => $symbol.number_format((float) $row->revenue),
            ])
            ->all();
    }
}
