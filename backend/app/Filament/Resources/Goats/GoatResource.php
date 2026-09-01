<?php

namespace App\Filament\Resources\Goats;

use App\Filament\Resources\Goats\Pages\CreateGoat;
use App\Filament\Resources\Goats\Pages\EditGoat;
use App\Filament\Resources\Goats\Pages\ListGoats;
use App\Filament\Resources\Goats\RelationManagers\ImagesRelationManager;
use App\Filament\Resources\Goats\RelationManagers\WeightsRelationManager;
use App\Filament\Resources\Goats\Schemas\GoatForm;
use App\Filament\Resources\Goats\Tables\GoatsTable;
use App\Models\Goat;
use App\Support\RestrictsAccessByRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class GoatResource extends Resource
{
    use RestrictsAccessByRole;

    protected static string $area = 'catalog';

    protected static ?string $model = Goat::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return GoatForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoatsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ImagesRelationManager::class,
            WeightsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    /** Listings waiting on staff take priority over the live count. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('approval_status', 'pending')->count();

        return $pending > 0
            ? (string) $pending
            : (string) static::getModel()::published()->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::where('approval_status', 'pending')->exists() ? 'warning' : 'gray';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'sku', 'breed'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoats::route('/'),
            'create' => CreateGoat::route('/create'),
            'edit' => EditGoat::route('/{record}/edit'),
        ];
    }
}
