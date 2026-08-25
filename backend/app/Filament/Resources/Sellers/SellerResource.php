<?php

namespace App\Filament\Resources\Sellers;

use App\Filament\Resources\Sellers\Pages\CreateSeller;
use App\Filament\Resources\Sellers\Pages\EditSeller;
use App\Filament\Resources\Sellers\Pages\ListSellers;
use App\Filament\Resources\Sellers\Schemas\SellerForm;
use App\Filament\Resources\Sellers\Tables\SellersTable;
use App\Models\Seller;
use BackedEnum;
use App\Support\RestrictsAccessByRole;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SellerResource extends Resource
{
    use RestrictsAccessByRole;

    protected static string $area = 'marketplace';

    protected static ?string $model = Seller::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|UnitEnum|null $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Sellers';

    protected static ?string $recordTitleAttribute = 'farm_name';

    public static function form(Schema $schema): Schema
    {
        return SellerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SellersTable::configure($table);
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
            //
        ];
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
            'index' => ListSellers::route('/'),
            'create' => CreateSeller::route('/create'),
            'edit' => EditSeller::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
