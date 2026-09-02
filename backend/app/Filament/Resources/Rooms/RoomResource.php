<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\RelationManagers\ImagesRelationManager;
use App\Filament\Resources\Rooms\Schemas\RoomForm;
use App\Filament\Resources\Rooms\Tables\RoomsTable;
use App\Models\Room;
use App\Support\RestrictsAccessByRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class RoomResource extends Resource
{
    use RestrictsAccessByRole;

    /**
     * Catalogue, not an area of its own.
     *
     * The navigation group is "Homestay" because that is how the farm thinks
     * about it, but a permission area is a different question: it asks who may
     * edit a thing the shop sells. A room is a listing -- photographed,
     * described, priced and published -- so whoever is trusted with the goats
     * is trusted with this. Minting a `homestay` area would have meant deciding
     * again, for every role, something already decided.
     */
    protected static string $area = 'catalog';

    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static string|UnitEnum|null $navigationGroup = 'Homestay';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoomsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ImagesRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    /** How many rooms are actually on offer. */
    public static function getNavigationBadge(): ?string
    {
        $published = static::getModel()::published()->count();

        return $published > 0 ? (string) $published : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'room_type'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRooms::route('/'),
            'create' => CreateRoom::route('/create'),
            'edit' => EditRoom::route('/{record}/edit'),
        ];
    }
}
