<?php

namespace App\Filament\Resources\Goats\Tables;

use App\Models\Goat;
use App\Models\Setting;
use App\Notifications\ListingReviewed;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class GoatsTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::currencyCode();

        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->disk('public')
                    ->height(48)
                    ->width(64),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn ($record) => $record->sku),

                TextColumn::make('seller.farm_name')
                    ->label('Seller')
                    ->searchable()
                    ->placeholder('House stock')
                    ->color(fn ($state) => $state ? null : 'gray')
                    ->toggleable(),

                TextColumn::make('approval_status')
                    ->label('Review')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->sortable(),

                TextColumn::make('category.name')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('breed')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('weight_kg')
                    ->label('Weight')
                    ->suffix(' kg')
                    ->sortable(),

                TextColumn::make('age_months')
                    ->label('Age')
                    ->suffix(' mo')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('gender')
                    ->badge()
                    ->colors(['info' => 'male', 'warning' => 'female'])
                    ->toggleable(),

                TextColumn::make('price')
                    ->money($currency)
                    ->sortable()
                    ->description(fn ($record) => $record->sale_price
                        ? 'On sale: '.number_format((float) $record->sale_price)
                        : null),

                TextColumn::make('stock')
                    ->badge()
                    ->color(fn ($record) => match (true) {
                        ! $record->track_stock => 'gray',
                        $record->stock <= 0 => 'danger',
                        $record->stock <= (int) Setting::get('low_stock_threshold', 2) => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(fn ($record) => $record->track_stock ? $record->stock : '∞')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'published',
                        'warning' => 'sold',
                        'danger' => 'archived',
                    ])
                    ->sortable(),

                ToggleColumn::make('is_featured')
                    ->label('Featured'),

                IconColumn::make('is_vaccinated')
                    ->label('Vaccinated')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('views')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'sold' => 'Sold',
                        'archived' => 'Archived',
                    ]),

                SelectFilter::make('approval_status')
                    ->label('Review status')
                    ->options([
                        'draft' => 'Draft',
                        'pending' => 'Awaiting review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),

                SelectFilter::make('seller_id')
                    ->label('Seller')
                    ->relationship('seller', 'farm_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('gender')
                    ->options(['male' => 'Male', 'female' => 'Female']),

                TernaryFilter::make('is_featured')->label('Featured'),

                TernaryFilter::make('in_stock')
                    ->label('Stock')
                    ->placeholder('All')
                    ->trueLabel('In stock')
                    ->falseLabel('Out of stock')
                    ->queries(
                        true: fn ($query) => $query->where(fn ($q) => $q->where('track_stock', false)->orWhere('stock', '>', 0)),
                        false: fn ($query) => $query->where('track_stock', true)->where('stock', '<=', 0),
                    ),

                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('approveListing')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Goat $record) => $record->approval_status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription('The listing goes live in the shop straight away.')
                    ->action(function (Goat $record): void {
                        $record->update([
                            'approval_status' => 'approved',
                            'rejection_reason' => null,
                            'approved_at' => now(),
                            'approved_by' => auth()->id(),
                            'status' => 'published',
                        ]);

                        $record->seller?->user?->notify(new ListingReviewed($record->fresh()));

                        Notification::make()->title($record->name.' is live')->success()->send();
                    }),

                Action::make('rejectListing')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Goat $record) => $record->approval_status === 'pending')
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('What needs changing?')
                            ->rows(3)
                            ->required()
                            ->helperText('Sent to the seller so they can fix it and resubmit.'),
                    ])
                    ->action(function (Goat $record, array $data): void {
                        $record->update([
                            'approval_status' => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                            'status' => 'draft',
                        ]);

                        $record->seller?->user?->notify(new ListingReviewed($record->fresh()));

                        Notification::make()->title($record->name.' sent back to the seller')->warning()->send();
                    }),

                EditAction::make(),
                ReplicateAction::make()
                    ->excludeAttributes(['slug', 'sku', 'views'])
                    ->label('Duplicate'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
