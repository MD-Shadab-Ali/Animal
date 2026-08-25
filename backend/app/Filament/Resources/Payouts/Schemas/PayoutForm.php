<?php

namespace App\Filament\Resources\Payouts\Schemas;

use App\Models\Payout;
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

                    TextInput::make('transaction_reference'),
                    Textarea::make('note')->rows(2)->columnSpanFull(),
                ]),

            // What the seller told us when they asked to be paid, copied onto
            // the payout at the time. Read-only on purpose: editing it here
            // would not change where the seller wants their money, and the
            // record should keep saying where this payout was sent.
            Section::make('Where this goes')
                ->description('Taken from the seller when the payout was raised. To change it, update the seller.')
                ->columns(2)
                ->schema([
                    TextInput::make('method')
                        ->label('Method')
                        ->formatStateUsing(fn (?string $state, ?Payout $record) => $record?->method_label ?? $state)
                        ->placeholder('Not set')
                        ->disabled(),
                    TextInput::make('bank_name')
                        ->label('Bank')
                        ->placeholder('Not applicable')
                        ->disabled(),
                    TextInput::make('account_name')
                        ->label('Name on the account')
                        ->placeholder('Not set')
                        ->disabled(),
                    TextInput::make('account_number')
                        ->label('Account number')
                        ->placeholder('Not set')
                        ->disabled(),
                ]),
        ]);
    }
}
