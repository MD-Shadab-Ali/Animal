<?php

namespace App\Filament\Resources\Payouts;

use App\Filament\Resources\Payouts\Pages\EditPayout;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Resources\Payouts\Schemas\PayoutForm;
use App\Filament\Resources\Payouts\Tables\PayoutsTable;
use App\Models\Payout;
use BackedEnum;
use App\Support\RestrictsAccessByRole;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class PayoutResource extends Resource
{
    use RestrictsAccessByRole;

    protected static string $area = 'marketplace';

    protected static ?string $model = Payout::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Payouts';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return PayoutForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayoutsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /** Payouts are generated from settled earnings, not created by hand. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayouts::route('/'),
            'edit' => EditPayout::route('/{record}/edit'),
        ];
    }
}
