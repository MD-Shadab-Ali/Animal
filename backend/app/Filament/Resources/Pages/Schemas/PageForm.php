<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $operation, $state, callable $set) => $operation === 'create'
                        ? $set('slug', Str::slug((string) $state))
                        : null),

                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->prefix('/pages/'),

                Textarea::make('excerpt')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('One line shown under the page title.'),

                RichEditor::make('body')->columnSpanFull(),
            ]),

            Section::make('Presentation')->columns(2)->schema([
                FileUpload::make('banner_image')
                    ->image()
                    ->imageEditor()
                    ->directory('pages')
                    ->maxSize(4096),

                TextInput::make('sort_order')->numeric()->default(0),

                Toggle::make('is_active')->label('Published')->default(true)->inline(false),
                Toggle::make('show_in_footer')->label('Link from the footer')->inline(false),
            ]),

            Section::make('Search engine listing')->collapsed()->schema([
                TextInput::make('meta_title')->maxLength(255),
                Textarea::make('meta_description')->rows(2)->maxLength(500),
            ]),
        ]);
    }
}
