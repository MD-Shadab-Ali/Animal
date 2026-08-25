<?php

namespace App\Filament\Resources\HomeSections\Schemas;

use App\Models\HomeSection;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class HomeSectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('type')
                    ->options(HomeSection::TYPES)
                    ->required()
                    ->native(false)
                    ->unique(ignoreRecord: true)
                    ->helperText('Decides which block the storefront renders.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers appear higher on the page.'),

                TextInput::make('title')->maxLength(255),
                TextInput::make('subtitle')->maxLength(255),

                Textarea::make('description')->rows(2)->columnSpanFull(),

                ColorPicker::make('background_color')
                    ->helperText('Leave empty for the default white background.'),

                Toggle::make('is_active')
                    ->label('Show this section')
                    ->default(true)
                    ->inline(false),
            ]),

            Section::make('Block settings')
                ->description('Options for this block, as JSON. For example {"limit": 8, "columns": 4}.')
                ->collapsed()
                ->schema([
                    Textarea::make('config')
                        ->hiddenLabel()
                        ->rows(8)
                        ->helperText('Used for things like how many goats to show, or the items in the "Why choose us" and "Stats" blocks.')
                        // The column is an array cast, so present it as readable JSON and parse it back.
                        ->formatStateUsing(fn ($state): string => filled($state)
                            ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            : '')
                        ->dehydrateStateUsing(fn (?string $state) => filled($state)
                            ? json_decode($state, true)
                            : [])
                        ->rule(fn () => function (string $attribute, $value, callable $fail) {
                            if (filled($value) && json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE) {
                                $fail('This must be valid JSON.');
                            }
                        }),
                ]),

            Section::make('Custom HTML')
                ->description('Only used by the "Custom HTML" section type.')
                ->collapsed()
                ->schema([
                    Textarea::make('custom_html')->hiddenLabel()->rows(6),
                ]),
        ]);
    }
}
