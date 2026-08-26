<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use App\Support\RestrictsAccessByRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class OrderResource extends Resource
{
    use RestrictsAccessByRole;

    protected static string $area = 'sales';

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            PaymentsRelationManager::class,
            StatusHistoriesRelationManager::class,
        ];
    }

    /** Orders still waiting on the farm to act. */
    /**
     * Everything sitting on a person: new orders, and delivered-but-unconfirmed.
     *
     * The second half used to be invisible. An order paid in full at checkout
     * has no payment left to close it, so it waits at "out for delivery" until
     * somebody confirms it arrived — and holds up the seller's earnings while
     * it waits.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getModel()::where('status', 'pending')->count()
            + static::getModel()::query()->awaitingDeliveryConfirmation()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['order_number', 'customer_name', 'customer_phone'];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view'  => ViewOrder::route('/{record}'),
            'edit'  => EditOrder::route('/{record}/edit'),
        ];
    }
}
