<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Setting;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * A payment is a ledger entry, so this screen only ever reads.
 *
 * Everything is a placeholder rather than a disabled input: half of what
 * matters here lives on the order, and editing any of it by hand would put
 * the order's totals out of step with the ledger they are derived from.
 */
class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (?float $amount) => Setting::currencySymbol().number_format((float) $amount, 2);

        return $schema->components([
            Section::make('Payment')
                ->description('Confirm or reject it from the list — that is what moves the order.')
                ->columns(3)
                ->schema([
                    Placeholder::make('reference')
                        ->content(fn (?Payment $record) => $record?->reference),

                    Placeholder::make('amount')
                        ->content(fn (?Payment $record) => $money((float) $record?->amount)),

                    Placeholder::make('type')
                        ->label('Kind')
                        ->content(fn (?Payment $record) => Payment::TYPES[$record?->type] ?? '—'),

                    Placeholder::make('method')
                        ->label('Paid by')
                        ->content(fn (?Payment $record) => $record?->method_label),

                    Placeholder::make('transaction_reference')
                        ->label('Transaction reference')
                        ->content(fn (?Payment $record) => $record?->transaction_reference ?: '—'),

                    Placeholder::make('status')
                        ->content(fn (?Payment $record) => Payment::STATUSES[$record?->status] ?? '—'),

                    Placeholder::make('source')
                        ->label('Logged by')
                        ->content(fn (?Payment $record) => $record?->source === 'customer'
                            ? 'The customer'
                            : 'Staff'),

                    Placeholder::make('paid_at')
                        ->label('Received on')
                        ->content(fn (?Payment $record) => $record?->paid_at?->format('d M Y, g:i a') ?: '—'),

                    Placeholder::make('confirmed')
                        ->label('Confirmed')
                        ->content(fn (?Payment $record) => $record?->confirmed_at
                            ? $record->confirmed_at->format('d M Y').' by '.($record->confirmer?->name ?? 'staff')
                            : 'Not yet'),

                    Placeholder::make('note')
                        ->content(fn (?Payment $record) => $record?->note ?: '—')
                        ->columnSpanFull(),
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

                    Placeholder::make('order_total')
                        ->label('Order total')
                        ->content(fn (?Payment $record) => $money((float) $record?->order?->total)),

                    Placeholder::make('received_so_far')
                        ->label('Received so far')
                        ->content(fn (?Payment $record) => $money((float) $record?->order?->paid_amount)),

                    Placeholder::make('order_status')
                        ->label('Order status')
                        ->content(fn (?Payment $record) => $record?->order?->status_label),
                ]),

            // The animals the money is for. Without this, "who paid for which
            // goat" means opening the order in another tab.
            Section::make('What this pays for')
                ->schema([
                    Placeholder::make('goats')
                        ->hiddenLabel()
                        ->content(fn (?Payment $record) => $record?->order
                            ? new HtmlString(
                                '<ul class="list-disc ps-5 space-y-1">'
                                .$record->order->items->map(fn (OrderItem $item) => '<li>'
                                    .e($item->goat_name)
                                    .' &times; '.$item->quantity
                                    .' &mdash; '.e(Setting::currencySymbol().number_format((float) $item->line_total, 2))
                                    .($item->seller_name ? ' <em>(from '.e($item->seller_name).')</em>' : '')
                                    .'</li>')->implode('')
                                .'</ul>'
                            )
                            : '—'),
                ]),
        ]);
    }
}
