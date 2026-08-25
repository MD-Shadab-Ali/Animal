<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $operation, $state, callable $set) => $operation === 'create'
                        ? $set('slug', Str::slug((string) $state))
                        : null),

                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Select::make('parent_id')
                    ->label('Parent category')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Top level'),

                TextInput::make('sort_order')->numeric()->default(0),

                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull()
                    ->helperText('Shown under the heading on the shop page.'),
            ]),

            Section::make('Appearance')->columns(2)->schema([
                FileUpload::make('image')
                    ->image()
                    ->imageEditor()
                    ->directory('categories')
                    ->maxSize(2048),

                TextInput::make('icon')
                    ->maxLength(60)
                    ->placeholder('bi-tag')
                    ->helperText('Bootstrap icon name, used when there is no image.'),

                Toggle::make('is_active')->label('Visible in the shop')->default(true)->inline(false),
                Toggle::make('is_featured')->label('Show on the homepage')->inline(false),
            ]),

            Section::make('Search engine listing')->collapsed()->schema([
                TextInput::make('meta_title')->maxLength(255),
                Textarea::make('meta_description')->rows(2)->maxLength(500),
            ]),
        ]);
    }
}
