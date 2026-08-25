<?php

namespace App\Filament\Resources\Goats\RelationManagers;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Gallery';

    protected static string|BackedEnum|null $icon = 'heroicon-o-photo';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')
                ->label('Photo')
                ->image()
                ->imageEditor()
                ->directory('goats/gallery')
                ->maxSize(4096)
                ->required()
                ->columnSpanFull(),

            TextInput::make('alt')
                ->label('Alt text')
                ->maxLength(255)
                ->helperText('Describes the photo for screen readers and search engines.'),

            TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('alt')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('path')
                    ->label('Photo')
                    ->disk('public')
                    ->height(64)
                    ->width(88),

                TextColumn::make('alt')
                    ->label('Alt text')
                    ->placeholder('—'),

                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add photo'),
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
