<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([

                Section::make('Order')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('order_number')->label('Number')->copyable(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => Order::STATUSES[$state] ?? $state)
                            ->color(fn (?string $state) => Order::STATUS_COLORS[$state] ?? 'gray'),
                        TextEntry::make('created_at')->label('Placed')->dateTime('d M Y, g:i a'),
                        TextEntry::make('delivered_at')->dateTime('d M Y, g:i a')->placeholder('Not yet delivered'),
                    ]),

                Section::make('Customer')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('customer_name')->label('Name'),
                        TextEntry::make('customer_phone')->label('Phone')->copyable(),
                        TextEntry::make('customer_email')->label('Email')->placeholder('—'),
                        TextEntry::make('user.email')->label('Account')->placeholder('—'),
                    ]),

                Section::make('Payment')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('payment_method')
                            ->formatStateUsing(fn (?string $state) => strtoupper((string) $state)),
                        TextEntry::make('payment_status')
                            ->badge()
                            ->colors([
                                'danger'  => 'unpaid',
                                'warning' => 'partially_paid',
                                'success' => 'paid',
                                'gray'    => 'refunded',
                            ]),
                        TextEntry::make('paid_amount')->money(fn ($record) => $record->currency),
                        TextEntry::make('transaction_id')->placeholder('—'),
                    ]),
            ]),

            Section::make('Delivery address')
                ->columns(3)
                ->schema([
                    TextEntry::make('address_line')->columnSpan(2),
                    TextEntry::make('area')->placeholder('—'),
                    TextEntry::make('city'),
                    TextEntry::make('postal_code')->placeholder('—'),
                    TextEntry::make('deliveryZone.name')->label('Zone')->placeholder('—'),
                    TextEntry::make('order_notes')->label('Customer note')->columnSpanFull()->placeholder('—'),
                ]),

            Section::make('Totals')
                ->columns(4)
                ->schema([
                    TextEntry::make('subtotal')->money(fn ($record) => $record->currency),
                    TextEntry::make('discount')->money(fn ($record) => $record->currency),
                    TextEntry::make('delivery_charge')->money(fn ($record) => $record->currency),
                    TextEntry::make('total')->money(fn ($record) => $record->currency)->weight('bold')->size('lg'),
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
