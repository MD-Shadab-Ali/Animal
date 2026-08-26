<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Models\Order;
use App\Models\Setting;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::currencyCode();

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->copyable(),

                TextColumn::make('customer_name')
                    ->searchable()
                    ->description(fn (Order $record) => $record->customer_phone),

                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('city')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('total')
                    ->money($currency)
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => strtoupper((string) $state))
                    ->toggleable(),

                TextColumn::make('payment_plan')
                    ->label('Plan')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'full'    => 'Paid up front',
                        'advance' => 'Advance',
                        default   => 'On delivery',
                    })
                    ->toggleable(),

                TextColumn::make('payment_status')
                    ->badge()
                    ->colors([
                        'danger'  => 'unpaid',
                        'warning' => 'partially_paid',
                        'success' => 'paid',
                        'gray'    => 'refunded',
                    ]),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Order::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => Order::STATUS_COLORS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('run_by')
                    ->label('Run by')
                    ->state(fn (Order $record) => $record->isSellerManaged()
                        ? ($record->items->first()?->seller_name ?? 'Seller')
                        : 'Our team')
                    ->badge()
                    ->color(fn (Order $record) => $record->isSellerManaged() ? 'info' : 'gray')
                    ->description(fn (Order $record) => $record->isSellerManaged()
                        ? 'seller-supplied'
                        : null),

                TextColumn::make('created_at')
                    ->label('Placed')
                    ->dateTime('d M Y, g:i a')
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),

                SelectFilter::make('status')
                    ->options(Order::STATUSES)
                    ->multiple(),

                SelectFilter::make('payment_status')
                    ->options([
                        'unpaid'         => 'Unpaid',
                        'partially_paid' => 'Partially paid',
                        'paid'           => 'Paid',
                        'refunded'       => 'Refunded',
                    ]),

                SelectFilter::make('delivery_zone_id')
                    ->label('Delivery zone')
                    ->relationship('deliveryZone', 'name'),

                Filter::make('today')
                    ->label('Placed today')
                    ->query(fn (Builder $query) => $query->whereDate('created_at', today())),
            ])
            ->recordActions([
                RestoreAction::make(),
                ForceDeleteAction::make(),
                ViewAction::make(),

                Action::make('updateStatus')
                    ->label('Update status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    // Staff and the seller both run seller-supplied orders, so
                    // the control stays available on every order.
                    ->schema([
                        Select::make('status')
                            ->label('New status')
                            // Delivered means paid for, so it is not on offer
                            // until the money is in — record the payment and
                            // the order closes itself.
                            ->options(fn (Order $record) => collect(Order::STATUSES)
                                ->reject(fn (string $label, string $status) => $status === 'delivered'
                                    && $record->status !== 'delivered'
                                    && ! $record->canBeDelivered())
                                ->all())
                            ->required()
                            ->native(false)
                            ->helperText(fn (Order $record) => $record->canBeDelivered()
                                ? null
                                : 'This order is not paid for yet, so it cannot be delivered.')
                            ->default(fn (Order $record) => $record->status),
                        Textarea::make('note')
                            ->label('Note (optional)')
                            ->rows(2),
                    ])
                    ->action(function (Order $record, array $data): void {
                        $record->update([
                            'status' => $data['status'],
                            'admin_note' => $data['note'] ?: $record->admin_note,
                        ]);

                        Notification::make()
                            ->title('Order '.$record->order_number.' is now '.($record->status_label))
                            ->success()
                            ->send();
                    }),

                Action::make('recordPayment')
                    ->label('Record payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Order $record) => $record->payment_status !== 'paid'
                        && $record->status !== 'cancelled')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount received')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default(fn (Order $record) => $record->balance_due)
                            ->helperText(fn (Order $record) => 'Outstanding balance: '
                                .number_format($record->balance_due, 2)),
                        TextInput::make('transaction_id')
                            ->label('Reference (optional)'),
                    ])
                    // Through the ledger, never straight onto the order: the
                    // order's paid_amount is derived from the payments, so a
                    // direct write here would be silently undone by the next sync.
                    ->action(function (Order $record, array $data): void {
                        $payment = app(PaymentService::class)->record($record, [
                            'amount'                => $data['amount'],
                            'method'                => $record->payment_method,
                            'transaction_reference' => $data['transaction_id'] ?: null,
                        ], auth()->user());

                        $record->refresh();

                        Notification::make()
                            ->title('Payment '.$payment->reference.' recorded')
                            ->body($record->status === 'delivered'
                                ? $record->order_number.' is settled and now marked delivered.'
                                : 'Outstanding: '.number_format($record->balance_due, 2))
                            ->success()
                            ->send();
                    }),

                // Kept on every order, including seller-run ones: without it a
                // disputed or fraudulent order could never be resolved.
                Action::make('cancelOrder')
                    ->label('Cancel order')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Order $record) => $record->status !== 'cancelled'
                        && $record->status !== 'delivered')
                    ->requiresConfirmation()
                    ->modalDescription('The goats go back on sale and the seller is told. Use this for disputes, not routine progress.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why are you cancelling?')
                            ->rows(2)
                            ->required(),
                    ])
                    ->action(function (Order $record, array $data): void {
                        $record->update([
                            'status'     => 'cancelled',
                            'admin_note' => trim($record->admin_note."
".'Cancelled by staff: '.$data['reason']),
                        ]);

                        Notification::make()
                            ->title($record->order_number.' cancelled')
                            ->body('Stock has been released.')
                            ->warning()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
