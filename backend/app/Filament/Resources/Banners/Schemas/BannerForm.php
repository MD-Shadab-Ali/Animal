<?php

namespace App\Filament\Resources\Banners\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->columns(2)->schema([
                Select::make('placement')
                    ->options([
                        'hero'         => 'Hero slider (homepage top)',
                        'promo_strip'  => 'Promo strip (mid page)',
                        'category_top' => 'Category page banner',
                        'sidebar'      => 'Sidebar',
                    ])
                    ->default('hero')
                    ->required()
                    ->native(false),

                TextInput::make('sort_order')->numeric()->default(0),

                TextInput::make('subtitle')
                    ->maxLength(255)
                    ->helperText('Small line above the headline.'),

                TextInput::make('title')
                    ->maxLength(255)
                    ->helperText('The headline.'),

                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),

            Section::make('Images')->columns(2)->schema([
                FileUpload::make('image')
                    ->image()
                    ->imageEditor()
                    ->directory('banners')
                    ->maxSize(4096)
                    ->helperText('16:9 image, around 1920x1080 — the hero frame crops to 16:9.'),

                FileUpload::make('mobile_image')
                    ->image()
                    ->imageEditor()
                    ->directory('banners')
                    ->maxSize(2048)
                    ->helperText('Optional portrait crop for phones.'),
            ]),

            Section::make('Button')->columns(2)->schema([
                TextInput::make('button_text')->maxLength(60),
                TextInput::make('button_link')
                    ->maxLength(255)
                    ->placeholder('/shop')
                    ->helperText('A storefront path, or a full URL.'),
            ]),

            Section::make('Appearance & scheduling')->columns(2)->collapsed()->schema([
                Select::make('text_align')
                    ->options(['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'])
                    ->default('left')
                    ->native(false),

                ColorPicker::make('overlay_color'),

                DateTimePicker::make('starts_at')
                    ->helperText('Leave blank to show immediately.'),

                DateTimePicker::make('ends_at')
                    ->helperText('Leave blank to run indefinitely.')
                    ->after('starts_at'),

                Toggle::make('is_active')->default(true)->inline(false)->columnSpanFull(),
            ]),
        ]);
    }
}
