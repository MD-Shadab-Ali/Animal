<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestOrders extends TableWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->canManage('sales') ?? false;
    }

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest orders';

    public function table(Table $table): Table
    {
        return $table
            ->query(OrderResource::getEloquentQuery()->latest()->limit(10))
            ->paginated(false)
            ->columns([
                TextColumn::make('order_number')->label('Order')->weight('medium'),
                TextColumn::make('customer_name')->label('Customer')
                    ->description(fn (Order $record) => $record->customer_phone),
                TextColumn::make('city'),
                TextColumn::make('total')->money(fn (Order $record) => $record->currency),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Order::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => Order::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('created_at')->label('Placed')->since(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('View')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
