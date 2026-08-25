<?php

namespace App\Filament\Resources\Payouts\Tables;

use App\Models\PaymentMethod;
use App\Models\Payout;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PayoutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->weight('medium')->copyable(),

                // Deliberately just the name. The phone that used to sit under it
                // read as the account number repeated — for a wallet the two are
                // the same number — and contact details belong on the seller.
                TextColumn::make('seller.farm_name')
                    ->label('Seller')
                    ->searchable(),

                TextColumn::make('items_count')
                    ->label('Sales')
                    ->counts('items')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('amount')->money(fn (Payout $record) => $record->currency)->weight('medium'),

                TextColumn::make('method')
                    ->label('Send via')
                    // The column stores the payment method code; show its name.
                    ->formatStateUsing(fn (?string $state) => $state
                        ? PaymentMethod::where('code', $state)->value('name') ?? $state
                        : null)
                    // The bank sits under the method, since one is useless
                    // without the other.
                    ->description(fn (Payout $record) => $record->bank_name)
                    ->placeholder('Not set'),

                TextColumn::make('account_number')
                    ->label('Account')
                    ->copyable()
                    ->copyMessage('Account number copied')
                    ->description(fn (Payout $record) => $record->account_name)
                    ->placeholder('Not set'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Payout::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => Payout::STATUS_COLORS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('paid_at')->label('Paid')->dateTime('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(Payout::STATUSES),
                SelectFilter::make('seller_id')->label('Seller')->relationship('seller', 'farm_name')->searchable(),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Payout $record) => $record->status !== 'paid')
                    ->modalHeading(fn (Payout $record) => 'Mark '.$record->reference.' paid')
                    ->schema([
                        // Read this before confirming, not after — these are the
                        // details the seller gave when they asked to be paid.
                        Placeholder::make('destination')
                            ->label('Send it to')
                            ->content(fn (Payout $record) => $record->destination
                                ?: 'This seller has not given any payout details.'),

                        TextInput::make('transaction_reference')
                            ->label('Transaction reference')
                            ->helperText('bKash trx id, bank reference, or whatever you have.'),
                    ])
                    ->action(function (Payout $record, array $data): void {
                        app(\App\Services\PayoutService::class)
                            ->markPaid($record, $data['transaction_reference'] ?? null);

                        Notification::make()->title($record->reference.' marked paid')->success()->send();
                    }),

                Action::make('markFailed')
                    ->label('Mark failed')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Payout $record) => $record->status !== 'paid')
                    ->requiresConfirmation()
                    ->modalDescription('The earnings go back into the seller\'s unpaid balance so they can be settled again.')
                    ->schema([
                        Textarea::make('note')->label('What went wrong?')->rows(2),
                    ])
                    ->action(function (Payout $record, array $data): void {
                        app(\App\Services\PayoutService::class)
                            ->markFailed($record, $data['note'] ?? null);

                        Notification::make()
                            ->title($record->reference.' failed')
                            ->body('The earnings are back in the queue.')
                            ->warning()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('No payouts yet')
            ->emptyStateDescription('Sellers request these from their earnings page, or settle one yourself from the Sellers screen.');
    }
}
