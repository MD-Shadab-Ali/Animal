<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Models\Room;
use App\Models\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::currencyCode();

        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
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
                    ->description(fn (Room $record) => $record->code),

                TextColumn::make('room_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),

                /*
                 * Both numbers in one cell, because either alone misleads. "4"
                 * reads as a room for four at the advertised price, and "2"
                 * reads as a room that cannot take a third at all.
                 */
                TextColumn::make('max_guests')
                    ->label('Sleeps')
                    ->formatStateUsing(fn (Room $record) => $record->base_guests === $record->max_guests
                        ? (string) $record->max_guests
                        : $record->base_guests.' – '.$record->max_guests)
                    ->description(fn (Room $record) => $record->base_guests === $record->max_guests
                        ? null
                        : 'rate covers '.$record->base_guests)
                    ->sortable(),

                TextColumn::make('price_per_night')
                    ->label('Per night')
                    ->money($currency)
                    ->sortable()
                    ->description(fn (Room $record) => $record->extra_guest_fee !== null
                        ? '+'.number_format((float) $record->extra_guest_fee).' per extra guest'
                        : null),

                TextColumn::make('min_nights')
                    ->label('Stay')
                    ->formatStateUsing(fn (Room $record) => $record->min_nights === $record->max_nights
                        ? $record->min_nights.' nights'
                        : $record->min_nights.' – '.$record->max_nights.' nights')
                    ->toggleable(),

                /*
                 * Stays still to come, which is the number staff actually want
                 * in front of them before archiving a room or changing a rate.
                 */
                TextColumn::make('bookings_count')
                    ->label('Upcoming')
                    ->counts(['bookings' => fn ($query) => $query->upcoming()])
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'info' : 'gray')
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'published',
                        'danger' => 'archived',
                    ])
                    ->sortable(),

                ToggleColumn::make('is_featured')
                    ->label('Featured'),

                IconColumn::make('has_private_bathroom')
                    ->label('Ensuite')
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
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ]),

                TernaryFilter::make('is_featured')->label('Featured'),

                TernaryFilter::make('has_private_bathroom')->label('Private bathroom'),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * A second room like this one, which is how a farm with three
                 * identical doubles fills them in. The slug and the code are
                 * excluded so the copy generates its own -- sharing either
                 * would collide on a unique index -- and the bookings stay
                 * behind, because they belong to the room that was let.
                 */
                ReplicateAction::make()
                    ->excludeAttributes(['slug', 'code', 'views'])
                    ->label('Duplicate'),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No rooms yet')
            ->emptyStateDescription('Add a room and it appears on the storefront with a calendar of its own.');
    }
}
