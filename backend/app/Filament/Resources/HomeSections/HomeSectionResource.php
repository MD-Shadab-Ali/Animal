<?php

namespace App\Filament\Resources\HomeSections;

use App\Filament\Resources\HomeSections\Pages\CreateHomeSection;
use App\Filament\Resources\HomeSections\Pages\EditHomeSection;
use App\Filament\Resources\HomeSections\Pages\ListHomeSections;
use App\Filament\Resources\HomeSections\Schemas\HomeSectionForm;
use App\Filament\Resources\HomeSections\Tables\HomeSectionsTable;
use App\Models\HomeSection;
use BackedEnum;
use App\Support\RestrictsAccessByRole;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class HomeSectionResource extends Resource
{
    use RestrictsAccessByRole;

    protected static string $area = 'content';

    protected static ?string $model = HomeSection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';

    protected static string|UnitEnum|null $navigationGroup = 'Storefront';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Homepage sections';

    public static function form(Schema $schema): Schema
    {
        return HomeSectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HomeSectionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomeSections::route('/'),
            'create' => CreateHomeSection::route('/create'),
            'edit' => EditHomeSection::route('/{record}/edit'),
        ];
    }
}
