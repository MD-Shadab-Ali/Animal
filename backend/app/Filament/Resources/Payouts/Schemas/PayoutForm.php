<?php

namespace App\Filament\Resources\Payouts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PayoutForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description('Payouts are generated from settled earnings. The amount and the sales it covers are fixed, so only the handling details can be edited here.')
                ->columns(2)
                ->schema([
                    TextInput::make('reference')->disabled(),
                    TextInput::make('amount')->disabled(),

                    Select::make('status')
                        ->options([
                            'pending'    => 'Pending',
                            'processing' => 'Processing',
                            'paid'       => 'Paid',
                            'failed'     => 'Failed',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Use the Mark paid / Mark failed actions on the list instead where you can — they also release or settle the earnings.'),

                    TextInput::make('method')->placeholder('bKash / Bank transfer'),
                    TextInput::make('transaction_reference'),
                    Textarea::make('note')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }
}
