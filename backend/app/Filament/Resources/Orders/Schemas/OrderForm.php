<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Goat;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')
                ->description('For phone and walk-in orders taken by staff.')
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->columns(2)
                ->schema([
                    Select::make('user_id')
                        ->label('Account')
                        ->relationship('user', 'name', fn ($query) => $query->where('role', 'customer'))
                        ->searchable(['name', 'email', 'phone'])
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $customer = User::find($state);

                            if (! $customer) {
                                return;
                            }

                            $set('customer_name', $customer->name);
                            $set('customer_phone', $customer->phone);
                            $set('customer_email', $customer->email);

                            $address = $customer->addresses()->orderByDesc('is_default')->first();

                            if ($address) {
                                $set('address_line', $address->address_line);
                                $set('area', $address->area);
                                $set('city', $address->city);
                                $set('postal_code', $address->postal_code);
                            }
                        })
                        ->helperText('Every order belongs to a customer account. Create one first if they are new.'),

                    Select::make('payment_method')
                        ->options(fn () => PaymentMethod::query()->orderBy('sort_order')->pluck('name', 'code'))
                        ->default('cod')
                        ->required()
                        ->native(false),
                ]),

            Section::make('Goats')
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->addActionLabel('Add a goat')
                        ->minItems(1)
                        ->defaultItems(1)
                        ->columns(3)
                        ->schema([
                            Select::make('goat_id')
                                ->label('Goat')
                                ->options(fn () => Goat::query()
                                    ->whereIn('status', ['published', 'draft'])
                                    ->orderBy('name')
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    $set('unit_price', Goat::find($state)?->effective_price);
                                }),

                            TextInput::make('quantity')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required(),

                            TextInput::make('unit_price')
                                ->label('Unit price')
                                ->numeric()
                                ->minValue(0)
                                ->helperText('Override for a haggled price.'),
                        ]),
                ]),

            Section::make('Charges')
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->columns(3)
                ->schema([
                    Select::make('delivery_zone_id')
                        ->label('Delivery zone')
                        ->relationship('deliveryZone', 'name')
                        ->native(false)
                        ->required(),

                    TextInput::make('delivery_charge')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Leave blank to use the zone rate.'),

                    TextInput::make('discount')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                ]),

            Grid::make(3)
                ->visible(fn (string $operation): bool => $operation === 'edit')
                ->schema([

                    Section::make('Fulfilment')
                        ->columnSpan(2)
                        ->columns(2)
                        ->schema([
                            Select::make('status')
                                // Where it is, and the one place it may go next.
                                // Anything else would let an order skip a state
                                // here that the orders list refuses to skip.
                                // The whole run is listed so the sequence is
                                // visible; only the reachable ones can be picked.
                                ->options(Order::STATUSES)
                                ->disableOptionWhen(function (string $value, ?Order $record): bool {
                                    if (! $record) {
                                        return false;
                                    }

                                    // Where it already is, so the form can hold it.
                                    if ($value === $record->status) {
                                        return false;
                                    }

                                    if ($value === 'cancelled') {
                                        return ! $record->isCancellable();
                                    }

                                    if ($value !== $record->nextStatus()) {
                                        return true;
                                    }

                                    return $value === 'delivered' && ! $record->canBeDelivered();
                                })
                                ->required()
                                ->native(false)
                                // Seller-supplied orders are run by the seller and by
                                // staff alike, so this stays editable on every order.
                                ->helperText(function (?Order $record): string {
                                    if ($record?->isSellerManaged()) {
                                        return 'Run by '.($record->items->first()?->seller_name ?? 'the seller')
                                            .'. They move it forward and so can you — the last change wins, '
                                            .'and every one is written to the order history below.';
                                    }

                                    if ($record && $record->nextStatus() === null) {
                                        return 'This order is finished — there is no step after '
                                            .($record->status_label).'.';
                                    }

                                    if ($record && $record->nextStatus() === 'delivered' && ! $record->canBeDelivered()) {
                                        return 'Delivered is unavailable until this order is paid for — record the '
                                            .'payment on the Payments tab and it will close itself.';
                                    }

                                    return 'Every change is written to the order history below.';
                                }),

                            TextInput::make('payment_status')
                                ->label('Payment')
                                ->formatStateUsing(fn (?string $state) => match ($state) {
                                    'partially_paid' => 'Partially paid',
                                    default => ucfirst((string) $state),
                                })
                                ->disabled()
                                ->helperText('Worked out from the payments below — record one there to change it.'),

                            TextInput::make('payment_plan')
                                ->label('Agreed at checkout')
                                ->formatStateUsing(fn (?string $state) => Order::PAYMENT_PLANS[$state] ?? $state)
                                ->disabled(),

                            TextInput::make('advance_required')
                                ->label('Wanted up front')
                                ->disabled()
                                ->prefix(fn () => Setting::currencySymbol())
                                ->helperText(fn (?Order $record) => match (true) {
                                    $record?->payment_plan === 'on_delivery' => 'Nothing — the buyer pays at the door.',
                                    (bool) $record?->awaiting_advance => 'Still outstanding.',
                                    default => 'Received.',
                                }),

                            TextInput::make('paid_amount')
                                ->label('Amount received')
                                ->disabled()
                                ->prefix(fn () => Setting::currencySymbol())
                                ->helperText(fn (?Order $record) => $record && $record->balance_due > 0
                                    ? 'Outstanding: '.Setting::currencySymbol().number_format($record->balance_due, 2)
                                    : 'Settled in full.'),

                            Textarea::make('admin_note')
                                ->label('Internal note')
                                ->rows(3)
                                ->columnSpanFull()
                                ->helperText('Only visible to staff.'),
                        ]),

                    Section::make('Summary')
                        ->columnSpan(1)
                        ->schema([
                            TextInput::make('order_number')->disabled(),
                            TextInput::make('payment_method')->disabled(),
                            TextInput::make('subtotal')->disabled()->prefix(fn () => Setting::currencySymbol()),

                            // Between the subtotal and the total, for the same
                            // reason the view page shows it: without it these four
                            // figures do not add up on a re-weighed order.
                            TextInput::make('weight_adjustment')
                                ->label('Weight adjustment')
                                ->disabled()
                                ->prefix(fn () => Setting::currencySymbol())
                                ->visible(fn (?Order $record) => (bool) $record?->hasWeightAdjustment())
                                ->helperText('Re-priced from the weight recorded at the door. The '
                                    .'subtotal above is still what was agreed at checkout.'),

                            TextInput::make('discount')->disabled()->prefix(fn () => Setting::currencySymbol()),
                            TextInput::make('delivery_charge')->disabled()->prefix(fn () => Setting::currencySymbol()),
                            TextInput::make('total')
                                ->label(fn (?Order $record) => $record?->hasWeightAdjustment() ? 'Total charged' : 'Total')
                                ->disabled()
                                ->prefix(fn () => Setting::currencySymbol()),
                        ]),
                ]),

            Section::make('Delivery address')
                ->columns(3)
                ->collapsible()
                ->schema([
                    TextInput::make('customer_name')->required(),
                    TextInput::make('customer_phone')->required()->tel(),
                    TextInput::make('customer_email')->email(),
                    TextInput::make('address_line')->required()->columnSpan(2),
                    TextInput::make('area'),
                    TextInput::make('city')->required(),
                    TextInput::make('postal_code'),
                    Select::make('delivery_zone_id')
                        ->label('Delivery zone')
                        ->relationship('deliveryZone', 'name')
                        ->native(false),
                    Textarea::make('order_notes')
                        ->label('Customer note')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
