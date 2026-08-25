<?php

namespace App\Filament\Resources\Menus\RelationManagers;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Menu items';

    protected static string|BackedEnum|null $icon = 'heroicon-o-bars-3';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')
                ->required()
                ->maxLength(255),

            TextInput::make('url')
                ->required()
                ->default('/')
                ->maxLength(255)
                ->helperText('A storefront path such as /shop, or a full external URL.'),

            Select::make('parent_id')
                ->label('Nest under')
                ->options(fn () => $this->getOwnerRecord()
                    ->items()
                    ->whereNull('parent_id')
                    ->pluck('label', 'id'))
                ->searchable()
                ->placeholder('Top level')
                ->helperText('Leave empty for a top-level link.'),

            TextInput::make('icon')
                ->maxLength(255)
                ->helperText('Optional Bootstrap icon name, e.g. bi-house.'),

            TextInput::make('sort_order')
                ->numeric()
                ->default(0),

            Toggle::make('open_in_new_tab')->inline(false),
            Toggle::make('is_active')->default(true)->inline(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->description(fn ($record) => $record->parent?->label
                        ? 'under '.$record->parent->label
                        : null),

                TextColumn::make('url')->color('gray'),

                IconColumn::make('open_in_new_tab')
                    ->label('New tab')
                    ->boolean()
                    ->toggleable(),

                ToggleColumn::make('is_active')->label('Active'),

                TextColumn::make('sort_order')->label('Order')->sortable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add link'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
