<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use BackedEnum;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Status history';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clock';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('to_status')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, g:i a'),

                TextColumn::make('from_status')
                    ->label('From')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? (Order::STATUSES[$state] ?? $state) : 'Created')
                    ->color(fn (?string $state) => $state ? (Order::STATUS_COLORS[$state] ?? 'gray') : 'gray'),

                TextColumn::make('to_status')
                    ->label('To')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Order::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => Order::STATUS_COLORS[$state] ?? 'gray'),

                TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('Customer'),

                TextColumn::make('note')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated(false);
    }
}
