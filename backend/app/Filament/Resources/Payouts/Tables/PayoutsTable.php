<?php

namespace App\Filament\Resources\Payouts\Tables;

use App\Models\Payout;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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

                TextColumn::make('seller.farm_name')
                    ->label('Seller')
                    ->searchable()
                    ->description(fn (Payout $record) => $record->seller?->contact_phone),

                TextColumn::make('items_count')
                    ->label('Sales')
                    ->counts('items')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('amount')->money(fn (Payout $record) => $record->currency)->weight('medium'),

                TextColumn::make('method')->placeholder('—')->toggleable(),

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
                    ->schema([
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
            ->emptyStateDescription('Settle a seller from the Sellers screen to create one.');
    }
}
