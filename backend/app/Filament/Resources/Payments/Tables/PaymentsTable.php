<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PaymentsTable
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
                    ->label('Paid by')
                    ->searchable()
                    ->description(fn (Payment $record) => $record->order?->customer_phone)
                    ->placeholder('Walk-in'),

                // Who paid for what, without opening the order.
                TextColumn::make('goats_summary')
                    ->label('For')
                    ->wrap()
                    ->tooltip(fn (Payment $record) => $record->goats()->implode(', '))
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->whereHas('order.items', fn (Builder $items) => $items
                            ->where('goat_name', 'like', "%{$search}%"))),

                TextColumn::make('amount')
                    ->money(fn (Payment $record) => $record->currency)
                    ->weight('medium')
                    ->color(fn (Payment $record) => $record->type === 'refund' ? 'danger' : null)
                    ->description(fn (Payment $record) => $record->type === 'refund' ? 'Refund' : null)
                    // The running total of what has actually been received.
                    // Summarizers are handed the query builder, not Eloquent's.
                    ->summarize(
                        Sum::make()
                            ->label('Received')
                            ->query(fn (QueryBuilder $query) => $query
                                ->where('status', 'confirmed')
                                ->where('type', 'payment'))
                            ->money(fn () => Setting::currencyCode())
                    ),

                TextColumn::make('method')
                    ->label('Via')
                    ->formatStateUsing(fn (Payment $record) => $record->method_label),

                TextColumn::make('transaction_reference')
                    ->label('Reference')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Payment::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => Payment::STATUS_COLORS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => $state === 'customer' ? 'From customer' : 'Staff')
                    ->toggleable(),

                TextColumn::make('paid_at')->label('Paid')->dateTime('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Logged')->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(Payment::STATUSES),
                SelectFilter::make('method')
                    ->options(fn () => PaymentMethod::orderBy('sort_order')->pluck('name', 'code')),
                Filter::make('today')
                    ->label('Received today')
                    ->query(fn (Builder $query) => $query->whereDate('paid_at', today())),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Payment $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription('Only confirm once you can see the money on the account. The order updates itself.')
                    ->action(function (Payment $record): void {
                        app(PaymentService::class)->confirm($record, auth()->user());

                        Notification::make()->title($record->reference.' confirmed')->success()->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Payment $record) => $record->status !== 'rejected')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->label('Why?')->rows(2)])
                    ->action(function (Payment $record, array $data): void {
                        app(PaymentService::class)->reject($record, $data['reason'] ?? null, auth()->user());

                        Notification::make()->title($record->reference.' rejected')->warning()->send();
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No payments yet')
            ->emptyStateDescription('Money shows up here as customers pay and as staff record what comes in.');
    }
}
