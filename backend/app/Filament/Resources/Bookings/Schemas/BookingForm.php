<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Models\Booking;
use App\Models\Setting;
use App\Services\BookingService;
use App\Support\Homestay;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        $symbol = Setting::currencySymbol();

        return $schema->components([

            Section::make('The stay')
                ->columns(2)
                ->description('Changing the dates moves which nights the room is held for. If they clash '
                    .'with another booking the save is refused and nothing changes.')
                ->schema([
                    Select::make('room_id')
                        ->label('Room')
                        ->relationship('room', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false),

                    Select::make('user_id')
                        ->label('Account')
                        ->relationship('user', 'name')
                        ->searchable(['name', 'email'])
                        ->preload()
                        ->required()
                        ->native(false)
                        ->helperText('Whose account the booking sits under. The guest below can be somebody else.'),

                    DatePicker::make('check_in')
                        ->label('Arrives')
                        ->required()
                        ->native(false)
                        // Only on create. An existing booking may perfectly well
                        // have started yesterday, and a minimum would make it
                        // unsaveable for the rest of its life.
                        ->minDate(fn (string $operation) => $operation === 'create'
                            ? Homestay::earliestDate()
                            : null)
                        ->maxDate(Homestay::latestDate()),

                    DatePicker::make('check_out')
                        ->label('Leaves')
                        ->required()
                        ->native(false)
                        ->maxDate(Homestay::latestDate()->addDay())
                        ->helperText('The departure day is not charged as a night, so the room is '
                            .'available again for whoever arrives that afternoon.'),

                    TextInput::make('guests')
                        ->label('Guests')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(20)
                        ->required()
                        ->default(1),
                ]),

            Section::make('Guest')
                ->columns(2)
                ->schema([
                    TextInput::make('guest_name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('guest_phone')
                        ->label('Phone')
                        ->required()
                        ->maxLength(30),

                    TextInput::make('guest_email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),

                    Textarea::make('guest_notes')
                        ->label('What they told us')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make('Payment')
                ->columns(2)
                ->schema([
                    Select::make('payment_method')
                        ->label('Paying by')
                        ->options(fn () => BookingService::paymentMethods()->pluck('name', 'code'))
                        ->required()
                        ->native(false)
                        ->helperText('Cash on delivery is not offered for a room — there is no door to '
                            .'collect at, and a room held for money that never arrives is a night lost.'),

                    Select::make('payment_plan')
                        ->label('Plan')
                        ->options(Booking::PAYMENT_PLANS)
                        ->required()
                        ->default('full')
                        ->native(false),
                ]),

            /*
             * Money, on edit only.
             *
             * On create these are worked out by BookingService from the room's
             * rate, so showing them would be inviting somebody to type a figure
             * that is about to be overwritten.
             *
             * On edit they are the agreed price and staff may change it -- but
             * nothing recalculates them, deliberately. Extending a stay by a
             * night does not silently re-price it, because a silent re-price
             * would also wipe out a discount somebody gave over the phone.
             * Whoever moves the dates decides what the money does.
             */
            Section::make('Money')
                ->columns(3)
                ->hiddenOn('create')
                ->description('These are the figures agreed with the guest. Moving the dates does not '
                    .'change them — adjust them here if the price has changed.')
                ->schema([
                    TextInput::make('room_charge')
                        ->label('Room')
                        ->numeric()
                        ->prefix($symbol),

                    TextInput::make('extra_guest_charge')
                        ->label('Extra guests')
                        ->numeric()
                        ->prefix($symbol),

                    TextInput::make('discount')
                        ->label('Discount')
                        ->numeric()
                        ->prefix($symbol),

                    TextInput::make('total')
                        ->label('Total')
                        ->numeric()
                        ->required()
                        ->prefix($symbol),

                    TextInput::make('advance_required')
                        ->label('Advance asked for')
                        ->numeric()
                        ->prefix($symbol)
                        ->helperText('Empty on a pay-in-full booking.'),

                    /*
                     * Read-only, and not merely as a courtesy: this is derived
                     * from the confirmed rows in the payment ledger, so a
                     * figure typed here would be silently overwritten the next
                     * time any payment on this booking was touched.
                     */
                    TextInput::make('paid_amount')
                        ->label('Paid so far')
                        ->disabled()
                        ->dehydrated(false)
                        ->prefix($symbol)
                        ->helperText('Comes from the Payments tab. Record money there, not here.'),
                ]),

            Section::make('Where it has got to')
                ->columns(2)
                ->hiddenOn('create')
                ->schema([
                    Select::make('status')
                        ->options(Booking::STATUSES)
                        ->required()
                        ->native(false)
                        ->helperText('The list screen has a one-step control that also writes the history '
                            .'row. Use this only to correct a mistake.'),

                    Textarea::make('admin_note')
                        ->label('Internal note')
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText('Never shown to the guest.'),
                ]),
        ]);
    }
}
