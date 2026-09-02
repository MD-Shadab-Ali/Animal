<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The money received against one booking.
 *
 * The same ledger the orders use, through the same service -- a booking is
 * simply the other thing a payment can be for. Recording the advance here is
 * what moves a stay from placed to confirmed.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    protected static string|BackedEnum|null $icon = 'heroicon-o-banknotes';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->copyable(),

                TextColumn::make('amount')
                    ->money(fn (Payment $record) => $record->currency)
                    ->weight('medium')
                    ->description(fn (Payment $record) => $record->type === 'refund' ? 'Refund' : null),

                TextColumn::make('method')
                    ->label('Via')
                    ->formatStateUsing(fn (Payment $record) => $record->method_label),

                TextColumn::make('transaction_reference')->label('Reference')->placeholder('—'),

                TextColumn::make('proof')
                    ->label('Receipt')
                    ->badge()
                    ->state(fn (Payment $record) => $record->hasProof() ? 'View' : 'None')
                    ->color(fn (Payment $record) => $record->hasProof() ? 'success' : 'gray')
                    ->icon(fn (Payment $record) => $record->hasProof() ? 'heroicon-o-paper-clip' : null)
                    ->url(fn (Payment $record) => $record->proof_url)
                    ->openUrlInNewTab(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Payment::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => Payment::STATUS_COLORS[$state] ?? 'gray'),

                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => Payment::SOURCES[$state] ?? $state),

                TextColumn::make('created_at')->label('Added')->since(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Record a payment')
                    ->modalHeading('Record money received')
                    ->modalDescription('Use this for cash handed over at the desk, or a transfer you can already see on the statement. It counts immediately.')
                    ->schema(fn () => self::recordSchema($this->getOwnerRecord()))
                    ->using(function (array $data): Payment {
                        return app(PaymentService::class)->record(
                            $this->getOwnerRecord(),
                            $data,
                            auth()->user()
                        );
                    }),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Payment $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription('Only confirm once you can see the money on the account.')
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
                    ->schema([
                        Textarea::make('reason')->label('Why?')->rows(2),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        app(PaymentService::class)->reject($record, $data['reason'] ?? null, auth()->user());

                        Notification::make()->title($record->reference.' rejected')->warning()->send();
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing received yet')
            ->emptyStateDescription('Record what the guest has paid and the booking confirms itself.');
    }

    /** @return array<int, mixed> */
    public static function recordSchema(?Booking $booking): array
    {
        return [
            Select::make('method')
                ->label('Paid by')
                ->options(fn () => PaymentMethod::query()->orderBy('sort_order')->pluck('name', 'code'))
                ->default($booking?->payment_method)
                ->required()
                ->native(false),

            TextInput::make('amount')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->prefix(fn () => Setting::currencySymbol())
                // What is owed *today*, which on an advance plan is the advance
                // rather than the whole stay.
                ->default(fn () => $booking && $booking->amount_due_now > 0 ? $booking->amount_due_now : null)
                ->helperText($booking
                    ? 'Outstanding on this booking: '.Setting::currencySymbol()
                        .number_format($booking->balance_due, 2)
                    : null),

            Select::make('type')
                ->options(Payment::TYPES)
                ->default('payment')
                ->required()
                ->native(false)
                ->helperText('A refund subtracts from what has been received.'),

            TextInput::make('transaction_reference')
                ->label('Transaction reference')
                ->helperText('eSewa or Khalti id, bank reference, or the receipt number.'),

            DateTimePicker::make('paid_at')
                ->label('Received on')
                ->default(now())
                ->seconds(false),

            Textarea::make('note')->rows(2)->columnSpanFull(),
        ];
    }
}
