<?php

namespace App\Filament\Resources\Reviews\Tables;

use App\Models\Review;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('goat.name')
                    ->label('Goat')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable(),

                TextColumn::make('rating')
                    ->badge()
                    ->formatStateUsing(fn (int $state) => str_repeat('*', $state).' '.$state.'/5')
                    ->color(fn (int $state) => match (true) {
                        $state >= 4 => 'success',
                        $state == 3 => 'warning',
                        default     => 'danger',
                    })
                    ->sortable(),

                TextColumn::make('title')->placeholder('—')->limit(30),

                TextColumn::make('comment')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('No comment'),

                IconColumn::make('is_approved')
                    ->label('Live')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Written')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),

                TernaryFilter::make('is_approved')
                    ->label('Approval')
                    ->placeholder('All reviews')
                    ->trueLabel('Published')
                    ->falseLabel('Awaiting moderation'),

                SelectFilter::make('rating')
                    ->options([5 => '5 stars', 4 => '4 stars', 3 => '3 stars', 2 => '2 stars', 1 => '1 star']),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Publish')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Review $record) => ! $record->is_approved)
                    ->action(function (Review $record): void {
                        $record->update(['is_approved' => true]);

                        Notification::make()->title('Review published')->success()->send();
                    }),

                Action::make('unapprove')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('warning')
                    ->visible(fn (Review $record) => $record->is_approved)
                    ->requiresConfirmation()
                    ->action(function (Review $record): void {
                        $record->update(['is_approved' => false]);

                        Notification::make()->title('Review hidden from the storefront')->success()->send();
                    }),

                RestoreAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveMany')
                        ->label('Publish selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $records->each->update(['is_approved' => true])),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No reviews yet')
            ->emptyStateDescription('Customers can review a goat once it has been delivered to them.');
    }
}
