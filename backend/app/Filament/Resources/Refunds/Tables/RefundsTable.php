<?php

namespace App\Filament\Resources\Refunds\Tables;

use App\Models\Payment;
use App\Models\Setting;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;

class RefundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->weight('medium')->copyable(),

                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->searchable()
                    ->url(fn (Payment $record) => $record->order
                        ? '/admin/orders/'.$record->order_id.'/edit'
                        : null),

                TextColumn::make('payer.name')
                    ->label('Owed to')
                    ->searchable()
                    ->description(fn (Payment $record) => $record->order?->customer_phone),

                TextColumn::make('goats_summary')
                    ->label('For')
                    ->wrap(),

                TextColumn::make('amount')
                    ->money(fn (Payment $record) => $record->currency)
                    ->weight('medium')
                    ->color('danger')
                    ->summarize(
                        Sum::make()
                            ->label('Refunded')
                            ->query(fn (QueryBuilder $query) => $query->where('status', 'confirmed'))
                            ->money(fn () => Setting::currencyCode())
                    ),

                // The whole point of the screen: where to send it back to.
                TextColumn::make('refund_to_account')
                    ->label('Send to')
                    ->copyable()
                    ->description(fn (Payment $record) => trim(implode(' · ', array_filter([
                        $record->refund_to_bank,
                        $record->refund_to_name,
                    ]))) ?: null)
                    ->placeholder('No details given'),

                TextColumn::make('method')
                    ->label('Via')
                    ->formatStateUsing(fn (Payment $record) => $record->method_label),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (Payment $record) => $record->status_label)
                    ->color(fn (?string $state) => Payment::STATUS_COLORS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('created_at')->label('Asked')->since()->sortable(),
                TextColumn::make('confirmed_at')->label('Sent')->dateTime('d M Y')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(Payment::REFUND_STATUSES),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('markRefunded')
                    ->label('Mark refunded')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Payment $record) => $record->status === 'pending')
                    ->modalHeading(fn (Payment $record) => 'Refund '.$record->reference)
                    ->modalDescription('Only tick this off once the money has actually left our account.')
                    ->schema([
                        TextInput::make('transaction_reference')
                            ->label('Transaction reference')
                            ->helperText('The id from the transfer, so the buyer can trace it.'),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        if (! empty($data['transaction_reference'])) {
                            $record->update(['transaction_reference' => $data['transaction_reference']]);
                        }

                        app(PaymentService::class)->confirm($record->fresh(), auth()->user());

                        Notification::make()
                            ->title($record->reference.' marked refunded')
                            ->body('The order no longer shows the money as received.')
                            ->success()
                            ->send();
                    }),

                Action::make('decline')
                    ->label('Decline')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Payment $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription('Use this when the refund is not owed. The buyer keeps seeing what they paid.')
                    ->schema([
                        Textarea::make('reason')->label('Why?')->rows(2)->required(),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        app(PaymentService::class)->reject($record, $data['reason'], auth()->user());

                        Notification::make()->title($record->reference.' declined')->warning()->send();
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing to refund')
            ->emptyStateDescription('Refunds show up here when a buyer asks for one on a cancelled order, or when staff record one against an order.');
    }
}
