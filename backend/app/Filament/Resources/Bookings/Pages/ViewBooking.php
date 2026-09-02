<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\Homestay;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([

                Section::make('Booking')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('booking_number')->label('Number')->copyable(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => Booking::STATUSES[$state] ?? $state)
                            ->color(fn (?string $state) => Booking::STATUS_COLORS[$state] ?? 'gray'),
                        TextEntry::make('created_at')->label('Booked')->dateTime('d M Y, g:i a'),
                        TextEntry::make('cancelled_at')->dateTime('d M Y, g:i a')->placeholder('—'),
                    ]),

                Section::make('Guest')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('guest_name')->label('Name'),
                        TextEntry::make('guest_phone')->label('Phone')->copyable(),
                        TextEntry::make('guest_email')->label('Email')->placeholder('—'),
                        TextEntry::make('user.email')->label('Account')->placeholder('—'),
                    ]),

                Section::make('Payment')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('payment_method')
                            ->formatStateUsing(fn (?string $state) => strtoupper((string) $state)),
                        TextEntry::make('payment_plan')
                            ->label('Plan')
                            ->formatStateUsing(fn (Booking $record) => $record->payment_plan_label),
                        TextEntry::make('payment_status')
                            ->badge()
                            ->colors([
                                'danger' => 'unpaid',
                                'warning' => 'partially_paid',
                                'success' => 'paid',
                                'gray' => 'refunded',
                            ]),
                        TextEntry::make('paid_amount')->money(fn ($record) => $record->currency),
                    ]),
            ]),

            /*
             * The three things somebody at a desk actually needs: which room,
             * which nights, and when the guest is expected through the door.
             * The times come from settings rather than from the booking,
             * because they are the farm's hours and not this guest's own
             * arrangement.
             */
            Section::make('The stay')
                ->columns(4)
                ->schema([
                    TextEntry::make('room_name')->label('Room')->weight('bold'),

                    TextEntry::make('check_in')
                        ->label('Arrives')
                        ->date('l d M Y')
                        ->helperText('From '.Homestay::checkInTime()),

                    TextEntry::make('check_out')
                        ->label('Leaves')
                        ->date('l d M Y')
                        ->helperText('By '.Homestay::checkOutTime()),

                    TextEntry::make('nights')
                        ->label('Nights')
                        ->formatStateUsing(fn (Booking $record) => $record->nights.' × '
                            .number_format((float) $record->rate_per_night, 2)),

                    TextEntry::make('guests')->label('Guests'),

                    TextEntry::make('guest_notes')
                        ->label('What they told us')
                        ->columnSpanFull()
                        ->placeholder('—'),
                ]),

            Section::make('Totals')
                ->columns(5)
                ->schema([
                    TextEntry::make('room_charge')->money(fn ($record) => $record->currency),
                    TextEntry::make('extra_guest_charge')
                        ->label('Extra guests')
                        ->money(fn ($record) => $record->currency),
                    TextEntry::make('discount')->money(fn ($record) => $record->currency),
                    TextEntry::make('total')
                        ->money(fn ($record) => $record->currency)
                        ->weight('bold')
                        ->size('lg'),

                    // What is owed today, which on an advance plan is not the
                    // same as what is owed altogether.
                    TextEntry::make('advance_required')
                        ->label('Due now')
                        ->state(fn (Booking $record) => $record->amount_due_now)
                        ->money(fn ($record) => $record->currency)
                        ->color(fn (Booking $record) => $record->amount_due_now > 0.009 ? 'warning' : 'success'),
                ]),

            Section::make('Internal note')
                ->collapsed()
                ->schema([
                    TextEntry::make('admin_note')->hiddenLabel()->placeholder('No note'),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
