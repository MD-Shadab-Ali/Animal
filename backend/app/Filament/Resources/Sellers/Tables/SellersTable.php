<?php

namespace App\Filament\Resources\Sellers\Tables;

use App\Models\Seller;
use App\Models\Setting;
use App\Notifications\SellerApplicationReviewed;
use App\Services\PayoutService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class SellersTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::currencyCode();

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('farm_name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Seller $record) => $record->user?->email),

                TextColumn::make('city')->searchable()->toggleable(),
                TextColumn::make('contact_phone')->label('Phone')->copyable()->toggleable(),

                TextColumn::make('goats_count')
                    ->label('Listings')
                    ->counts('goats')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('commission_rate')
                    ->label('Commission')
                    ->formatStateUsing(fn (Seller $record) => $record->effective_commission_rate.'%')
                    ->description(fn (Seller $record) => $record->commission_rate === null ? 'default' : 'custom'),

                TextColumn::make('unpaid')
                    ->label('Owed')
                    ->state(fn (Seller $record) => $record->unpaid_earnings)
                    ->money($currency)
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),

                IconColumn::make('id_document')
                    ->label('ID')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-check')
                    ->falseIcon('heroicon-o-document-minus')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip(fn (Seller $record) => $record->id_document
                        ? 'ID document on file'
                        : 'No ID document — do not approve')
                    ->getStateUsing(fn (Seller $record) => filled($record->id_document)),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger'  => 'suspended',
                        'gray'    => 'rejected',
                    ])
                    ->sortable(),

                TextColumn::make('created_at')->label('Applied')->since()->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('status')->options([
                    'pending'   => 'Pending review',
                    'approved'  => 'Approved',
                    'suspended' => 'Suspended',
                    'rejected'  => 'Rejected',
                ]),
            ])
            ->recordActions(static::rowActions())
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('No sellers yet')
            ->emptyStateDescription('People who apply through the storefront appear here for review.');
    }

    private static function rowActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('They can then create listings, which staff still review one by one.')
                ->visible(fn (Seller $record) => $record->status !== 'approved')
                ->action(function (Seller $record): void {
                    $record->update([
                        'status'      => 'approved',
                        'approved_at' => now(),
                        'approved_by' => auth()->id(),
                    ]);

                    $record->user?->notify(new SellerApplicationReviewed($record->fresh()));

                    Notification::make()->title($record->farm_name.' can now sell')->success()->send();
                }),

            Action::make('reject')
                ->label(fn (Seller $record) => $record->status === 'approved' ? 'Suspend' : 'Reject')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (Seller $record) => $record->status !== 'rejected')
                ->schema([
                    Textarea::make('review_note')
                        ->label('Reason')
                        ->rows(3)
                        ->required()
                        ->helperText('Included in the email we send them.'),
                ])
                ->action(function (Seller $record, array $data): void {
                    $record->update([
                        'status'      => $record->status === 'approved' ? 'suspended' : 'rejected',
                        'review_note' => $data['review_note'],
                    ]);

                    $record->user?->notify(new SellerApplicationReviewed($record->fresh()));

                    Notification::make()
                        ->title($record->farm_name.' is now '.$record->status)
                        ->warning()
                        ->send();
                }),

            Action::make('payout')
                ->label('Pay out')
                ->icon('heroicon-o-banknotes')
                ->color('primary')
                ->visible(fn (Seller $record) => $record->unpaid_earnings > 0)
                ->requiresConfirmation()
                ->modalDescription(fn (Seller $record) => 'This settles '
                    .number_format($record->unpaid_earnings, 2)
                    .' of delivered earnings into a new payout record.')
                ->action(function (Seller $record): void {
                    try {
                        $payout = app(PayoutService::class)->settle($record, auth()->user());

                        Notification::make()
                            ->title('Payout '.$payout->reference.' created')
                            ->body('Mark it paid once the money has gone out.')
                            ->success()
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Could not create the payout')
                            ->body(collect($exception->errors())->flatten()->first())
                            ->danger()
                            ->send();
                    }
                }),

            RestoreAction::make()
                ->label('Restore')
                ->modalDescription('Brings the application back exactly as it was, still needing review.'),

            ForceDeleteAction::make()
                ->label('Delete permanently')
                ->modalDescription('Removes the application for good. The person can then apply again from scratch.'),

            EditAction::make(),
        ];
    }
}
