<?php

namespace App\Filament\Resources\Refunds\Schemas;

use App\Models\Payment;
use App\Models\Setting;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Read-only: act on a refund from the list, where the money actually moves. */
class RefundForm
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (?float $amount) => Setting::currencySymbol().number_format((float) $amount, 2);

        return $schema->components([
            Section::make('Refund')
                ->columns(3)
                ->schema([
                    Placeholder::make('reference')
                        ->content(fn (?Payment $record) => $record?->reference),
                    Placeholder::make('amount')
                        ->content(fn (?Payment $record) => $money((float) $record?->amount)),
                    Placeholder::make('status')
                        ->content(fn (?Payment $record) => $record?->status_label),

                    Placeholder::make('asked')
                        ->label('Asked for')
                        ->content(fn (?Payment $record) => $record?->created_at?->format('d M Y, g:i a')),
                    Placeholder::make('sent')
                        ->label('Sent')
                        ->content(fn (?Payment $record) => $record?->confirmed_at
                            ? $record->confirmed_at->format('d M Y').' by '.($record->confirmer?->name ?? 'staff')
                            : 'Not yet'),
                    Placeholder::make('transaction_reference')
                        ->label('Transaction reference')
                        ->content(fn (?Payment $record) => $record?->transaction_reference ?: '—'),

                    Placeholder::make('reason')
                        ->label('Why they cancelled')
                        ->content(fn (?Payment $record) => $record?->refund_reason ?: '—')
                        ->columnSpanFull(),
                ]),

            Section::make('Send it to')
                ->description('Given by the buyer. It is not always the account the money came from.')
                ->columns(3)
                ->schema([
                    Placeholder::make('method')
                        ->label('Method')
                        ->content(fn (?Payment $record) => $record?->method_label),
                    Placeholder::make('refund_to_bank')
                        ->label('Bank')
                        ->content(fn (?Payment $record) => $record?->refund_to_bank ?: 'Not applicable'),
                    Placeholder::make('refund_to_name')
                        ->label('Account name')
                        ->content(fn (?Payment $record) => $record?->refund_to_name ?: '—'),
                    Placeholder::make('refund_to_account')
                        ->label('Account number')
                        ->content(fn (?Payment $record) => $record?->refund_to_account ?: '—'),
                ]),

            Section::make('Order')
                ->columns(3)
                ->schema([
                    Placeholder::make('order_number')
                        ->label('Order')
                        ->content(fn (?Payment $record) => $record?->order?->order_number),
                    Placeholder::make('customer')
                        ->content(fn (?Payment $record) => $record?->order?->customer_name),
                    Placeholder::make('phone')
                        ->content(fn (?Payment $record) => $record?->order?->customer_phone),
                    Placeholder::make('goats')
                        ->label('What was ordered')
                        ->content(fn (?Payment $record) => $record?->goats()->implode(', ') ?: '—'),
                    Placeholder::make('order_total')
                        ->label('Order total')
                        ->content(fn (?Payment $record) => $money((float) $record?->order?->total)),
                    Placeholder::make('still_held')
                        ->label('Still held')
                        ->content(fn (?Payment $record) => $money((float) $record?->order?->paid_amount)),
                ]),
        ]);
    }
}
