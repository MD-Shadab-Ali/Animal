<?php

namespace App\Filament\Widgets;

use App\Models\OrderItem;
use App\Models\Setting;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Built on Filament's own table rather than a hand-written Blade view.
 *
 * The hand-written version styled itself with Tailwind utilities, and this
 * panel registers no custom theme -- Filament serves its own compiled CSS,
 * which carries only the classes Filament itself uses. Everything this widget
 * asked for was therefore absent at runtime and the markup collapsed into a
 * plain stack of text. Filament's table brings its own styling, dark mode and
 * responsive behaviour, so there is nothing left to compile.
 */
class TopSellingGoats extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->canManage('catalog') ?? false;
    }

    public function table(Table $table): Table
    {
        $symbol = Setting::currencySymbol();

        return $table
            ->heading('Best sellers')
            ->description('By revenue, excluding cancelled orders.')
            ->query(
                OrderItem::query()
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    // Deliberately unfiltered on deleted_at: a sold goat that
                    // has since been taken down still sold, and its name is
                    // the only one left to show.
                    ->leftJoin('goats', 'goats.id', '=', 'order_items.goat_id')
                    ->whereNull('orders.deleted_at')
                    ->whereNot('orders.status', 'cancelled')
                    // MIN(id) only so each grouped row has a key of its own;
                    // the figures beside it are the group's, not that line's.
                    //
                    // The name shown is the live one, falling back to the
                    // snapshot for a goat that no longer exists. Ranking on
                    // the snapshot is what hid a genuine best seller: renaming
                    // a goat split its sales into one row per name it had ever
                    // been sold under, and neither half made the cut.
                    ->selectRaw("MIN(order_items.id) as id,
                                 COALESCE(MAX(goats.name), MAX(order_items.goat_name)) as goat_name,
                                 MAX(order_items.goat_sku) as goat_sku,
                                 MAX(order_items.seller_name) as seller_name,
                                 SUM(order_items.quantity) as sold,
                                 SUM(order_items.line_total
                                     + COALESCE(order_items.price_adjustment, 0)) as revenue")
                    // One row per goat. Falls back to the SKU, then the name,
                    // for lines whose goat has been deleted outright -- those
                    // still have to rank, and they must not all collapse into
                    // a single row on a null id.
                    ->groupByRaw("COALESCE(
                        CONCAT('g:', order_items.goat_id),
                        CONCAT('s:', order_items.goat_sku),
                        CONCAT('n:', order_items.goat_name)
                    )")
                    ->orderByDesc('revenue')
                    ->limit(5)
            )
            ->paginated(false)
            // The ranking is the ORDER BY above. Filament would otherwise
            // append its own sort on the key column, which is an aggregate
            // here and would be rejected outright under ONLY_FULL_GROUP_BY.
            ->defaultKeySort(false)
            ->emptyStateHeading('Nothing has sold yet')
            ->emptyStateDescription('This fills in as orders come through.')
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->columns([
                TextColumn::make('rank')
                    ->label('#')
                    ->rowIndex()
                    ->badge()
                    ->color('primary')
                    ->alignCenter(),

                TextColumn::make('goat_name')
                    ->label('Goat')
                    ->weight('medium')
                    // Whose stock it was matters on a marketplace board: house
                    // stock and a seller's listing rank side by side here.
                    ->description(fn (OrderItem $record) => $record->seller_name
                        ? $record->goat_sku.' · '.$record->seller_name
                        : $record->goat_sku)
                    ->wrap(),

                TextColumn::make('sold')
                    ->label('Sold')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => (int) $state),

                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->weight('semibold')
                    ->alignEnd()
                    // Kept on the settings symbol, like the rest of this
                    // dashboard, rather than Filament's ISO-code money format.
                    ->formatStateUsing(fn ($state) => $symbol.number_format((float) $state)),
            ]);
    }
}
