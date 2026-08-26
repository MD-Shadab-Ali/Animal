<?php

namespace App\Filament\Resources\Goats\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class GoatForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Goat')->columnSpanFull()->tabs([

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

                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')->required(),
                                Textarea::make('description'),
                            ]),

                        Select::make('seller_id')
                            ->label('Sold by')
                            ->relationship('seller', 'farm_name')
                            ->searchable()
                            ->preload()
                            ->placeholder('The house farm')
                            ->helperText('Leave empty for our own stock. Seller listings pay commission.'),

                        TextInput::make('sku')
                            ->label('SKU')
                            ->unique(ignoreRecord: true)
                            ->placeholder('Generated automatically if left blank'),

                        Textarea::make('short_description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('Shown on the goat card in the shop listing.'),

                        RichEditor::make('description')
                            ->columnSpanFull(),
                    ]),
                ]),

                Tab::make('Livestock')->icon('heroicon-o-clipboard-document-check')->schema([
                    Section::make('Physical')->columns(3)->schema([
                        TextInput::make('breed')->maxLength(255),
                        Select::make('gender')
                            ->options(['male' => 'Male (buck)', 'female' => 'Female (doe)'])
                            ->required()
                            ->default('male'),
                        TextInput::make('color')->maxLength(255),
                        TextInput::make('age_months')
                            ->label('Age (months)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(300),
                        TextInput::make('weight_kg')
                            ->label('Weight (kg)')
                            ->numeric()
                            ->step(0.1)
                            ->minValue(0),
                        TextInput::make('teeth')
                            ->label('Permanent teeth')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(8)
                            ->helperText('0, 2, 4, 6 or 8'),
                    ]),

                    Section::make('Health')->columns(2)->schema([
                        TextInput::make('health_status')
                            ->maxLength(255)
                            ->placeholder('Vet checked — healthy'),
                        Toggle::make('is_vaccinated')
                            ->label('Vaccinated')
                            ->inline(false),
                    ]),

                    Section::make('Extra specifications')
                        ->description('Add any spec row you like — it appears in the storefront spec table.')
                        ->schema([
                            Repeater::make('specs')
                                ->hiddenLabel()
                                ->schema([
                                    TextInput::make('label')->required(),
                                    TextInput::make('value')->required(),
                                ])
                                ->columns(2)
                                ->addActionLabel('Add specification')
                                ->reorderable()
                                ->collapsed()
                                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                                ->default([]),
                        ]),
                ]),

                Tab::make('Pricing & stock')->icon('heroicon-o-banknotes')->schema([
                    Section::make()->columns(2)->schema([
                        TextInput::make('price')
                            ->label('Asking price')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->prefix(fn () => \App\Models\Setting::currencySymbol())
                            ->helperText('For the weight on the Livestock tab. Heavier animals '
                                .'are priced up from here.'),

                        TextInput::make('sale_price')
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->prefix(fn () => \App\Models\Setting::currencySymbol())
                            ->helperText('Leave blank for no discount. Must be lower than the price.')
                            ->lte('price'),

                        TextInput::make('min_weight_kg')
                            ->label('Lightest you can supply')
                            ->numeric()
                            ->minValue(0.5)
                            ->suffix('kg')
                            ->live(onBlur: true)
                            ->lte('weight_kg')
                            ->helperText('Leave blank to start at the listed weight. Cannot be '
                                .'above it — the weight on the Livestock tab is what this '
                                .'listing advertises.'),

                        TextInput::make('max_weight_kg')
                            ->label('Heaviest you can supply')
                            ->numeric()
                            ->minValue(0.5)
                            ->suffix('kg')
                            ->live(onBlur: true)
                            ->gte('weight_kg')
                            ->helperText('Leave blank to sell this one animal at its own weight. '
                                .'Fill it in and buyers can ask for anything up to this, priced '
                                .'at the rate below.'),

                        Placeholder::make('weight_range_note')
                            ->label('')
                            ->columnSpanFull()
                            ->content(function (callable $get): string {
                                $anchor = (float) $get('weight_kg');
                                $step   = (float) ($get('weight_step_kg') ?: 1);
                                $floor  = (float) ($get('min_weight_kg') ?: $anchor);
                                $top    = (float) ($get('max_weight_kg') ?: $anchor);

                                if ($anchor <= 0 || $step <= 0 || $top <= $floor) {
                                    return 'Set a lightest and heaviest above to offer a choice '
                                        .'of weights.';
                                }

                                // Same grid the buyer's selector will land on:
                                // counted outward from the advertised weight so
                                // that weight is always one of the stops.
                                $low  = $anchor - floor(($anchor - min($floor, $anchor)) / $step + 0.000001) * $step;
                                $high = $anchor + floor((max($top, $anchor) - $anchor) / $step + 0.000001) * $step;

                                return 'Buyers will choose between '.round($low, 2).' kg and '
                                    .round($high, 2).' kg, in '.round($step, 2).' kg steps, '
                                    .'counted out from the listed '.round($anchor, 2).' kg.';
                            }),

                        TextInput::make('weight_step_kg')
                            ->label('Steps of')
                            ->numeric()
                            ->minValue(0.1)
                            ->default(1)
                            ->suffix('kg')
                            ->helperText('What the weight selector moves in — 1 kg is usual.'),

                        // Shown, never typed: an asking price against a weight
                        // is already a rate, and a third box to fill in is just
                        // a third thing that can disagree with the other two.
                        Placeholder::make('derived_rate')
                            ->label('Rate per kg')
                            ->columnSpanFull()
                            ->content(function (callable $get): string {
                                $symbol = \App\Models\Setting::currencySymbol();
                                $weight = (float) $get('weight_kg');
                                $sale   = (float) $get('sale_price');
                                $price  = $sale > 0 && $sale < (float) $get('price')
                                    ? $sale
                                    : (float) $get('price');

                                if ($weight <= 0 || $price <= 0) {
                                    return 'Set a weight on the Livestock tab and an asking price '
                                        .'here, and the rate works itself out.';
                                }

                                $rate = number_format($price / $weight, 2);
                                $max  = (float) $get('max_weight_kg');

                                $line = $symbol.$rate.' / kg — worked out from '.$symbol
                                    .number_format($price, 2).' at '.$weight.' kg.';

                                if ($max > $weight) {
                                    $line .= ' At '.$max.' kg that is '.$symbol
                                        .number_format($price * $max / $weight, 2).'.';
                                }

                                return $line;
                            }),

                        Toggle::make('track_stock')
                            ->label('Track stock for this goat')
                            ->default(true)
                            ->live()
                            ->inline(false),

                        TextInput::make('stock')
                            ->numeric()
                            ->minValue(0)
                            ->default(1)
                            ->visible(fn (callable $get): bool => (bool) $get('track_stock'))
                            ->helperText('Most goats are one-of-a-kind, so this is usually 1.'),
                    ]),
                ]),

                Tab::make('Media')->icon('heroicon-o-photo')->schema([
                    Section::make()->schema([
                        FileUpload::make('thumbnail')
                            ->label('Main photo')
                            ->image()
                            ->imageEditor()
                            ->directory('goats')
                            ->maxSize(4096)
                            ->helperText('Shown on the shop card. Additional photos go in the Gallery tab below the form.'),

                        TextInput::make('video_url')
                            ->label('Video URL')
                            ->url()
                            ->placeholder('https://youtube.com/watch?v=...'),
                    ]),
                ]),

                Tab::make('Publishing')->icon('heroicon-o-globe-alt')->schema([
                    Section::make()->columns(2)->schema([
                        Select::make('status')
                            ->options([
                                'draft'     => 'Draft — hidden from the shop',
                                'published' => 'Published — visible and buyable',
                                'sold'      => 'Sold',
                                'archived'  => 'Archived',
                            ])
                            ->default('published')
                            ->required(),

                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower numbers appear first.'),

                        Select::make('approval_status')
                            ->label('Review status')
                            ->options([
                                'draft'    => 'Draft',
                                'pending'  => 'Awaiting review',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->default('approved')
                            ->required()
                            ->live()
                            ->native(false)
                            ->helperText('A listing must be Approved and Published to appear in the shop.'),

                        Textarea::make('rejection_reason')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(fn (callable $get): bool => $get('approval_status') === 'rejected'),

                        Toggle::make('is_featured')
                            ->label('Show in the Featured goats section')
                            ->inline(false),
                    ]),

                    Section::make('Search engine listing')
                        ->collapsed()
                        ->schema([
                            TextInput::make('meta_title')->maxLength(255),
                            Textarea::make('meta_description')->rows(2)->maxLength(500),
                        ]),
                ]),

            ]),
        ]);
    }
}
