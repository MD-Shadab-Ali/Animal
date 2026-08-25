<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use BackedEnum;
use App\Models\OrderItem;
use App\Services\SellerFulfilmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Goats in this order';

    protected static string|BackedEnum|null $icon = 'heroicon-o-list-bullet';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('goat_name')
            ->columns([
                ImageColumn::make('goat_thumbnail')
                    ->label('')
                    ->disk('public')
                    ->height(44)
                    ->width(60),

                TextColumn::make('goat_name')
                    ->label('Goat')
                    ->description(fn ($record) => $record->goat_sku)
                    ->url(fn ($record) => $record->goat_id
                        ? route('filament.admin.resources.goats.edit', $record->goat_id)
                        : null),

                TextColumn::make('unit_price')
                    ->label('Unit price')
                    ->money(fn ($record) => $record->order->currency),

                TextColumn::make('quantity')
                    ->label('Qty')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('line_total')
                    ->label('Total')
                    ->money(fn ($record) => $record->order->currency)
                    ->weight('medium'),

                TextColumn::make('seller_name')
                    ->label('Seller')
                    ->placeholder('House stock')
                    ->color(fn ($state) => $state ? null : 'gray'),

                TextColumn::make('fulfilment_status')
                    ->label('Supplier progress')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OrderItem::FULFILMENT_STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => OrderItem::FULFILMENT_COLORS[$state] ?? 'gray')
                    ->description(fn (OrderItem $record) => $record->fulfilment_note)
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('setFulfilment')
                    ->label('Set progress')
                    ->icon('heroicon-o-arrow-path')
                    ->schema([
                        Select::make('fulfilment_status')
                            ->label('Supplier progress')
                            ->options(OrderItem::FULFILMENT_STATUSES)
                            ->required()
                            ->native(false)
                            ->default(fn (OrderItem $record) => $record->fulfilment_status)
                            ->helperText('Staff can set this to anything; the seller can only move it forward.'),
                    ])
                    ->action(function (OrderItem $record, array $data): void {
                        $record->update([
                            'fulfilment_status'     => $data['fulfilment_status'],
                            'fulfilment_updated_at' => now(),
                        ]);

                        // Same roll-up the seller path uses, so the buyer's
                        // order status keeps pace with what suppliers report.
                        app(SellerFulfilmentService::class)
                            ->syncOrderStatusFromLines($record->order);

                        Notification::make()->title('Line updated')->success()->send();
                    }),
            ])
            ->toolbarActions([])
            ->paginated(false);
    }
}
