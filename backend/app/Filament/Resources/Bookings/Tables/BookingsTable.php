<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Models\Booking;
use App\Models\Setting;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class BookingsTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::currencyCode();

        return $table
            // Newest booking first, as every other sales table is ordered: the
            // list answers "what has just come in" by default. Who is arriving
            // is a different question, and the arrival filters below answer it
            // without making staff read a date column to find today.
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('booking_number')
                    ->label('Booking')
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),

                TextColumn::make('room_name')
                    ->label('Room')
                    ->searchable()
                    ->description(fn (Booking $record) => $record->guests.' guest'
                        .((int) $record->guests === 1 ? '' : 's')),

                TextColumn::make('guest_name')
                    ->label('Guest')
                    ->searchable()
                    ->description(fn (Booking $record) => $record->guest_phone),

                TextColumn::make('check_in')
                    ->label('Arrives')
                    ->date('D d M Y')
                    ->sortable()
                    ->description(fn (Booking $record) => $record->nights.' night'
                        .((int) $record->nights === 1 ? '' : 's')),

                TextColumn::make('check_out')
                    ->label('Leaves')
                    ->date('D d M Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('total')
                    ->money($currency)
                    ->sortable()
                    ->description(fn (Booking $record) => $record->balance_due > 0.009
                        ? 'Outstanding: '.number_format($record->balance_due, 2)
                        : 'Settled'),

                TextColumn::make('payment_status')
                    ->label('Money')
                    ->badge()
                    ->colors([
                        'danger' => 'unpaid',
                        'warning' => 'partially_paid',
                        'success' => 'paid',
                        'gray' => 'refunded',
                    ])
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Booking::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => Booking::STATUS_COLORS[$state] ?? 'gray')
                    ->sortable(),

                // Shown by default, and to the minute: it is what the table is
                // sorted by, so hiding it would leave the order looking arbitrary
                // and several same-day bookings looking interchangeable.
                TextColumn::make('created_at')
                    ->label('Booked')
                    ->dateTime('d M Y, g:i a')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                /*
                 * Unconfirmed holds are kept out of the way, not thrown away.
                 *
                 * A booking exists from the moment a guest picks their nights,
                 * before any money has moved, and this list is read as the list
                 * of stays that are actually happening -- so a hold nobody has
                 * paid for does not belong in it by default.
                 *
                 * A filter rather than a scope on the resource, deliberately.
                 * That hold is keeping the room off the calendar: nothing in
                 * the system expires one, and cancelling it here is the only
                 * thing that hands those nights back. Made unreachable it would
                 * become a room blocked for dates nobody can explain, so it
                 * stays one click away, with the chip above the table saying
                 * where it went.
                 */
                Filter::make('confirmed_only')
                    ->label('Hide unconfirmed holds')
                    ->default()
                    ->query(fn ($query) => $query->where('status', '!=', 'placed')),

                SelectFilter::make('status')->options(Booking::STATUSES),

                SelectFilter::make('room_id')
                    ->label('Room')
                    ->relationship('room', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('payment_status')
                    ->label('Money')
                    ->options([
                        'unpaid' => 'Unpaid',
                        'partially_paid' => 'Part paid',
                        'paid' => 'Paid',
                        'refunded' => 'Refunded',
                    ]),

                /*
                 * The two questions actually asked at a front desk, as filters
                 * rather than as something to work out from a date column.
                 */
                Filter::make('arriving_today')
                    ->label('Arriving today')
                    ->query(fn ($query) => $query->whereDate('check_in', today())
                        ->whereNotIn('status', ['cancelled', 'checked_out'])),

                // Through the scope, which bounds this by the dates as well as
                // the status. Paying in full checks a guest in whenever they
                // pay, so a December stay settled today is `checked_in` today
                // -- and a flat status filter would put them on tonight's list.
                Filter::make('in_house')
                    ->label('In the house now')
                    ->query(fn ($query) => $query->inHouse()),

                TrashedFilter::make(),
            ])
            ->recordActions([
                RestoreAction::make(),
                ForceDeleteAction::make(),
                ViewAction::make(),

                /*
                 * One step, in order -- the same control the orders table
                 * offers, and for the same reason. Picking a status out of the
                 * middle would let a stay reach "checked out" having never been
                 * confirmed, leaving the guest's history describing a sequence
                 * that never happened.
                 */
                Action::make('updateStatus')
                    ->label('Update status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->visible(fn (Booking $record): bool => $record->nextStatus() !== null
                        || $record->isCancellable())
                    ->schema([
                        Select::make('status')
                            ->label('New status')
                            // Every status is listed so the whole run stays
                            // visible and staff can see where a stay sits in
                            // it. Only the reachable one can be picked.
                            ->options(Booking::STATUSES)
                            ->disableOptionWhen(function (string $value, Booking $record): bool {
                                // Cancelling is not a step in the sequence. It
                                // stops being available once somebody has
                                // arrived, because a room that was slept in
                                // cannot be handed back to the calendar.
                                if ($value === 'cancelled') {
                                    return ! $record->isCancellable();
                                }

                                if ($value !== $record->nextStatus()) {
                                    return true;
                                }

                                // Checked out means paid for, exactly as
                                // delivered does on an order.
                                return $value === 'checked_out' && ! $record->canCheckOut();
                            })
                            ->required()
                            ->native(false)
                            ->helperText(fn (Booking $record) => $record->nextStatus() === 'checked_out'
                                && ! $record->canCheckOut()
                                    ? 'There is still money outstanding on this stay, so it cannot be '
                                        .'checked out. Record the balance in the Payments tab first.'
                                    : null)
                            // Only ever defaults to something selectable: a
                            // pre-filled but disabled value fails validation
                            // the moment Submit is pressed.
                            ->default(function (Booking $record): ?string {
                                $next = $record->nextStatus();

                                if ($next === 'checked_out' && ! $record->canCheckOut()) {
                                    return null;
                                }

                                return $next;
                            }),

                        Textarea::make('note')
                            ->label('Note (optional)')
                            ->rows(2)
                            ->helperText('Kept on the booking’s history, so it is there when somebody asks '
                                .'later what happened.'),
                    ])
                    ->action(function (Booking $record, array $data): void {
                        // Handed to the observer, which writes it onto the
                        // history row this update is about to create.
                        $record->statusNote = $data['note'] ?: null;

                        $record->update(['status' => $data['status']]);

                        Notification::make()
                            ->title($record->booking_number.' is now '.$record->fresh()->status_label)
                            ->success()
                            ->send();
                    }),

                Action::make('recordPayment')
                    ->label('Record payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Booking $record) => $record->payment_status !== 'paid'
                        && $record->status !== 'cancelled')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount received')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->default(fn (Booking $record) => $record->amount_due_now ?: $record->balance_due)
                            ->helperText(fn (Booking $record) => 'Outstanding on this booking: '
                                .number_format($record->balance_due, 2)),

                        TextInput::make('transaction_reference')
                            ->label('Reference (optional)'),
                    ])
                    // Through the ledger, never straight onto the booking: its
                    // paid_amount is derived from the payments, so a direct
                    // write here would be undone by the next sync.
                    ->action(function (Booking $record, array $data): void {
                        $payment = app(PaymentService::class)->record($record, [
                            'amount' => $data['amount'],
                            'method' => $record->payment_method,
                            'transaction_reference' => $data['transaction_reference'] ?: null,
                        ], auth()->user());

                        $record->refresh();

                        Notification::make()
                            ->title('Payment '.$payment->reference.' recorded')
                            ->body($record->status === 'confirmed'
                                ? $record->booking_number.' is confirmed and the room is held.'
                                : 'Outstanding: '.number_format($record->balance_due, 2))
                            ->success()
                            ->send();
                    }),

                Action::make('cancelBooking')
                    ->label('Cancel booking')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Booking $record) => $record->isCancellable())
                    ->requiresConfirmation()
                    ->modalDescription('The nights go back on the calendar straight away and anyone can book '
                        .'them. Any money already taken becomes refundable.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why are you cancelling?')
                            ->rows(2)
                            ->required(),
                    ])
                    ->action(function (Booking $record, array $data): void {
                        $record->statusNote = 'Cancelled by staff: '.$data['reason'];

                        $record->update([
                            'status' => 'cancelled',
                            'admin_note' => trim($record->admin_note."\n"
                                .'Cancelled by staff: '.$data['reason']),
                        ]);

                        Notification::make()
                            ->title($record->booking_number.' cancelled')
                            ->body('The room is available again on those nights.')
                            ->warning()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No bookings yet')
            ->emptyStateDescription('Stays booked on the storefront appear here, and you can take one over the phone with New booking.');
    }
}
