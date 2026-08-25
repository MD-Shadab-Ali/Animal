<?php

namespace App\Filament\Resources\PaymentMethods\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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
                    ->required(),
                Toggle::make('requires_advance')
                    ->required(),
                TextInput::make('advance_amount')
                    ->numeric(),
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
