<?php

namespace App\Filament\Resources\PaymentMethods\Schemas;

use App\Models\Setting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                Textarea::make('instructions')
                    ->columnSpanFull(),
                FileUpload::make('logo')
                    ->image()
                    ->directory('payment-methods')
                    ->maxSize(1024),
                Toggle::make('is_active')
                    ->label('Active at checkout')
                    ->helperText('Buyers can choose this when they place an order.')
                    ->required(),
                Toggle::make('on_delivery_only')
                    ->label('Only for settling on delivery')
                    ->helperText('On: shown at checkout but greyed out — buyers cannot place an order with it, and staff use it to record the cash handed over at the door. This is what Cash on Delivery is.'),
                Toggle::make('supports_payout')
                    ->label('Available for seller payouts')
                    ->helperText('Sellers can pick this as the rail we send their earnings on. Needs to be active too.')
                    ->live(),
                TextInput::make('refund_eta')
                    ->label('Money sent on this arrives')
                    ->placeholder('straight away')
                    ->helperText('Completes "Refunds by this method usually arrive ___". A wallet is instant; a bank transfer is not. Leave empty to promise nothing.')
                    ->visible(fn ($get) => (bool) $get('supports_payout')),
                Toggle::make('requires_bank_name')
                    ->label('Ask the seller for their bank name')
                    ->helperText('Turn on for bank transfers — an account number alone is not enough to send to.')
                    ->visible(fn ($get) => (bool) $get('supports_payout')),
                Section::make('Money up front')
                    ->description('What a buyer has to pay when they place the order, before the goat is reserved for them.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('requires_advance')
                            ->label('Insist on payment up front')
                            ->helperText('On: the buyer must pay an advance or the full amount to place the order. Off: they may also choose to pay on delivery.')
                            ->columnSpanFull()
                            ->live(),

                        Select::make('advance_type')
                            ->label('Advance is')
                            ->options([
                                'percent' => 'A percentage of the order',
                                'fixed'   => 'A fixed amount',
                            ])
                            ->default('percent')
                            ->native(false)
                            ->live(),

                        TextInput::make('advance_amount')
                            ->label(fn ($get) => $get('advance_type') === 'fixed' ? 'Advance amount' : 'Advance percentage')
                            ->numeric()
                            ->minValue(0)
                            // The suffix is the whole point: 30 means very
                            // different things as a percentage and as rupees.
                            ->suffix(fn ($get) => $get('advance_type') === 'fixed' ? Setting::currencySymbol() : '%')
                            ->maxValue(fn ($get) => $get('advance_type') === 'fixed' ? null : 100)
                            ->placeholder(fn ($get) => $get('advance_type') === 'fixed' ? '5000' : '30')
                            ->helperText('Leave empty to use the site-wide advance percentage from Site settings.'),

                        Placeholder::make('advance_preview')
                            ->label('On a 50,000 order')
                            ->content(function ($get): string {
                                $amount = $get('advance_amount');

                                if (blank($amount)) {
                                    $percent = (float) Setting::get('advance_percent', 30);

                                    return Setting::currencySymbol().number_format(50000 * $percent / 100, 2)
                                        .' (site default of '.$percent.'%)';
                                }

                                $value = $get('advance_type') === 'fixed'
                                    ? (float) $amount
                                    : 50000 * (float) $amount / 100;

                                return Setting::currencySymbol().number_format(min($value, 50000), 2);
                            }),
                    ]),

                Section::make('Where the customer sends the money')
                    ->description('Shown to the buyer on their order so they can pay before delivery. Leave empty for cash on delivery — there is nothing to send.')
                    ->columns(2)
                    ->columnSpanFull()
                    // Never collapsed when empty: an active method with no
                    // account is exactly the case that needs filling in.
                    ->collapsed(false)
                    ->schema([
                        TextInput::make('payee_account_name')
                            ->label('Account name')
                            ->placeholder('Goat Haven Pvt Ltd'),
                        TextInput::make('payee_account_number')
                            ->label('Account or wallet number')
                            ->placeholder('98XXXXXXXX')
                            ->helperText('Filling this in is what turns on the "pay now" panel for buyers.'),
                        TextInput::make('payee_bank_name')
                            ->label('Bank')
                            ->placeholder('Only for bank transfers'),
                        FileUpload::make('payee_qr_image')
                            ->label('QR code')
                            ->image()
                            ->directory('payment-methods/qr')
                            ->maxSize(1024)
                            ->helperText('Optional. Wallet users would rather scan than type.'),
                    ]),

                KeyValue::make('config')
                    ->label('Gateway credentials')
                    ->keyLabel('Setting')
                    ->valueLabel('Value')
                    ->helperText('API keys and gateway options. Never exposed through the public API.')
                    ->columnSpanFull(),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
