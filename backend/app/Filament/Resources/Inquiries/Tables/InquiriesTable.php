<?php

namespace App\Filament\Resources\Inquiries\Tables;

use App\Models\Inquiry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InquiriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (Inquiry $record) => $record->phone),

                TextColumn::make('goat.name')
                    ->label('About')
                    ->searchable()
                    ->limit(30)
                    ->url(fn (Inquiry $record) => $record->goat_id
                        ? route('filament.admin.resources.goats.edit', $record->goat_id)
                        : null)
                    ->placeholder('General'),

                TextColumn::make('message')->wrap()->limit(80)->placeholder('No message'),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'new',
                        'info'    => 'contacted',
                        'success' => 'closed',
                    ])
                    ->sortable(),

                TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'new'       => 'New',
                        'contacted' => 'Contacted',
                        'closed'    => 'Closed',
                    ]),
            ])
            ->recordActions([
                Action::make('markContacted')
                    ->label('Mark contacted')
                    ->icon('heroicon-o-phone')
                    ->color('info')
                    ->visible(fn (Inquiry $record) => $record->status === 'new')
                    ->action(function (Inquiry $record): void {
                        $record->update(['status' => 'contacted']);
                        Notification::make()->title('Marked as contacted')->success()->send();
                    }),

                Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Inquiry $record) => $record->status !== 'closed')
                    ->action(function (Inquiry $record): void {
                        $record->update(['status' => 'closed']);
                        Notification::make()->title('Enquiry closed')->success()->send();
                    }),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No enquiries yet')
            ->emptyStateDescription('These arrive from the "Ask about this goat" form on a goat page.');
    }
}
