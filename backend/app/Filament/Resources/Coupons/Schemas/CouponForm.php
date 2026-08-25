<?php

namespace App\Filament\Resources\Coupons\Schemas;

use App\Models\Setting;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(40)
                    ->default(fn () => Str::upper(Str::random(8)))
                    ->helperText('Customers type this at checkout. Case does not matter.')
                    ->dehydrateStateUsing(fn (string $state): string => Str::upper(trim($state))),

                TextInput::make('description')
                    ->maxLength(255)
                    ->helperText('Shown to the customer once applied.'),
            ]),

            Section::make('Discount')->columns(3)->schema([
                Select::make('type')
                    ->options(['percent' => 'Percentage off', 'fixed' => 'Fixed amount off'])
                    ->default('percent')
                    ->required()
                    ->live()
                    ->native(false),

                TextInput::make('value')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->suffix(fn (callable $get) => $get('type') === 'percent' ? '%' : null)
                    ->prefix(fn (callable $get) => $get('type') === 'fixed'
                        ? Setting::get('currency_symbol', '')
                        : null),

                TextInput::make('max_discount')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (callable $get) => $get('type') === 'percent')
                    ->helperText('Caps a percentage discount.'),

                TextInput::make('min_order_amount')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Order must reach this before the code works.'),
            ]),

            Section::make('Limits')->columns(3)->schema([
                TextInput::make('usage_limit')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Total redemptions. Blank means unlimited.'),

                TextInput::make('usage_limit_per_user')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Per customer. Blank means unlimited.'),

                TextInput::make('used_count')
                    ->numeric()
                    ->default(0)
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('How many times it has been used.'),
            ]),

            Section::make('Availability')->columns(3)->schema([
                DateTimePicker::make('starts_at')->helperText('Blank means active now.'),
                DateTimePicker::make('expires_at')->after('starts_at')->helperText('Blank means no expiry.'),
                Toggle::make('is_active')->default(true)->inline(false),
            ]),
        ]);
    }
}
