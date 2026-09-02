<?php

namespace App\Filament\Resources\DeliveryZones\Schemas;

use App\Models\Setting;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeliveryZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('What the customer sees at checkout, e.g. "Inside Dhaka".'),

                TextInput::make('estimated_time')
                    ->maxLength(60)
                    ->placeholder('1-2 days'),

                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Which areas this covers.'),

                TextInput::make('charge')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->prefix(fn () => Setting::get('currency_symbol', '')),

                TextInput::make('free_above')
                    ->numeric()
                    ->minValue(0)
                    ->prefix(fn () => Setting::get('currency_symbol', ''))
                    ->helperText('Orders over this amount ship free. Blank to always charge.'),

                TextInput::make('sort_order')->numeric()->default(0),

                Toggle::make('is_pickup')
                    ->label('Buyer collects from the farm')
                    ->helperText('Turns the address questions at checkout into a collection '
                        .'time. Set the charge to 0 -- nothing is being delivered.')
                    ->default(false)
                    ->inline(false),

                Toggle::make('is_active')->label('Offer this zone')->default(true)->inline(false),
            ]),
        ]);
    }
}
