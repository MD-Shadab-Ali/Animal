<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Setting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        $symbol = Setting::currencySymbol();

        return $schema->components([
            Tabs::make('Room')->columnSpanFull()->tabs([

                Tab::make('Details')->icon('heroicon-o-identification')->schema([
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
                            ->maxLength(255)
                            ->helperText('Used in the storefront URL.'),

                        TextInput::make('code')
                            ->label('Room code')
                            ->unique(ignoreRecord: true)
                            ->placeholder('Generated automatically if left blank')
                            ->helperText('What staff call it on a key fob or a whiteboard.'),

                        TextInput::make('room_type')
                            ->label('Type')
                            ->maxLength(255)
                            ->placeholder('Double, twin, family'),

                        FileUpload::make('thumbnail')
                            ->label('Main photo')
                            ->image()
                            ->imageEditor()
                            ->directory('rooms')
                            ->maxSize(4096)
                            ->columnSpanFull()
                            ->helperText('Shown on the room card. More photos go in the Gallery tab.'),

                        Textarea::make('short_description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('Shown on the room card in the listing.'),

                        RichEditor::make('description')
                            ->columnSpanFull(),
                    ]),
                ]),

                Tab::make('The room')->icon('heroicon-o-key')->schema([
                    Section::make('Who it sleeps')
                        ->columns(3)
                        ->description('The rate covers the base number. Anyone above that is charged the extra guest fee, and nobody above the maximum can book at all.')
                        ->schema([
                            TextInput::make('base_guests')
                                ->label('Rate covers')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(20)
                                ->required()
                                ->default(2)
                                ->suffix('guests'),

                            TextInput::make('max_guests')
                                ->label('Sleeps at most')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(20)
                                ->required()
                                ->default(2)
                                ->suffix('guests')
                                ->helperText('Raised to match the base if you set it lower.'),

                            TextInput::make('beds')
                                ->label('Beds')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(20)
                                ->default(1),

                            Toggle::make('has_private_bathroom')
                                ->label('Private bathroom')
                                ->inline(false)
                                ->default(true),
                        ]),

                    Section::make('Amenities')
                        ->description('Anything you add here appears in the room’s detail table on the storefront.')
                        ->schema([
                            Repeater::make('amenities')
                                ->hiddenLabel()
                                ->schema([
                                    TextInput::make('label')->required()->placeholder('Hot water'),
                                    TextInput::make('value')->required()->placeholder('All day'),
                                ])
                                ->columns(2)
                                ->defaultItems(0)
                                ->addActionLabel('Add amenity')
                                ->reorderable()
                                ->collapsible(),
                        ]),
                ]),

                Tab::make('Rates')->icon('heroicon-o-banknotes')->schema([
                    Section::make()->columns(2)->schema([
                        TextInput::make('price_per_night')
                            ->label('Per night')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix($symbol),

                        TextInput::make('extra_guest_fee')
                            ->label('Each extra guest, per night')
                            ->numeric()
                            ->minValue(0)
                            ->prefix($symbol)
                            ->helperText('Leave empty and extra guests cost nothing — the maximum above is what limits them.'),

                        TextInput::make('min_nights')
                            ->label('Shortest stay')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(255)
                            ->required()
                            ->default(1)
                            ->suffix('nights'),

                        TextInput::make('max_nights')
                            ->label('Longest stay')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->required()
                            ->default(14)
                            ->suffix('nights')
                            ->helperText('Raised to match the shortest if you set it lower.'),
                    ]),

                    Section::make()
                        ->schema([
                            Textarea::make('homestay_note')
                                ->hiddenLabel()
                                ->disabled()
                                ->dehydrated(false)
                                ->rows(2)
                                ->default('Check-in and check-out times, how much notice a booking needs and '
                                    .'how far ahead the calendar runs are set once for the whole farm, '
                                    .'in Site settings → Homestay.'),
                        ]),
                ]),

                Tab::make('Visibility')->icon('heroicon-o-eye')->schema([
                    Section::make()->columns(2)->schema([
                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'published' => 'Published',
                                'archived' => 'Archived',
                            ])
                            ->required()
                            ->default('published')
                            ->native(false)
                            ->helperText('Only published rooms can be booked. Existing bookings are unaffected.'),

                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower numbers come first.'),

                        Toggle::make('is_featured')
                            ->label('Feature on the homepage')
                            ->inline(false),
                    ]),

                    Section::make('Search engines')->columns(2)->schema([
                        TextInput::make('meta_title')
                            ->maxLength(255)
                            ->placeholder('Defaults to the room name'),

                        Textarea::make('meta_description')
                            ->rows(2)
                            ->maxLength(500)
                            ->placeholder('Defaults to the short description'),
                    ]),
                ]),
            ]),
        ]);
    }
}
