<?php

namespace App\Filament\Resources\Refunds;

use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Resources\Refunds\Pages\ViewRefund;
use App\Filament\Resources\Refunds\Schemas\RefundForm;
use App\Filament\Resources\Refunds\Tables\RefundsTable;
use App\Models\Payment;
use App\Support\RestrictsAccessByRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Money going back to buyers.
 *
 * The same ledger as Payments, read from the other end: a refund row subtracts
 * from what an order has received, so cancelling a part-paid order leaves a
 * visible debt here rather than quietly keeping the buyer's advance.
 */
class RefundResource extends Resource
{
    use RestrictsAccessByRole;

    protected static string $area = 'sales';

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Refunds';

    protected static ?string $modelLabel = 'refund';

    protected static ?string $recordTitleAttribute = 'reference';

    /** This screen is only ever the refund half of the ledger. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', 'refund');
    }

    public static function form(Schema $schema): Schema
    {
        return RefundForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RefundsTable::configure($table);
    }

    /** Raised by the buyer, or from the order's own Payments tab. */
    public static function canCreate(): bool
    {
        return false;
    }

    /** Refunds asked for and not yet sent. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefunds::route('/'),
            'view'  => ViewRefund::route('/{record}'),
        ];
    }
}
