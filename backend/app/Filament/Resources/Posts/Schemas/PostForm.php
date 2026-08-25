<?php

namespace App\Filament\Resources\Posts\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PostForm
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
                    ->prefix('/blog/'),

                Select::make('post_category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([TextInput::make('name')->required()]),

                Select::make('user_id')
                    ->label('Author')
                    ->relationship('author', 'name')
                    ->default(fn () => auth()->id())
                    ->searchable(),

                Textarea::make('excerpt')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Shown on the blog listing card.'),

                RichEditor::make('body')->columnSpanFull(),
            ]),

            Section::make('Publishing')->columns(2)->schema([
                FileUpload::make('cover_image')
                    ->image()
                    ->imageEditor()
                    ->directory('posts')
                    ->maxSize(4096),

                DateTimePicker::make('published_at')
                    ->helperText('Leave blank to stamp the moment you publish.'),

                Toggle::make('is_published')->label('Published')->inline(false),
                Toggle::make('is_featured')->label('Featured')->inline(false),
            ]),

            Section::make('Search engine listing')->collapsed()->schema([
                TextInput::make('meta_title')->maxLength(255),
                Textarea::make('meta_description')->rows(2)->maxLength(500),
            ]),
        ]);
    }
}
