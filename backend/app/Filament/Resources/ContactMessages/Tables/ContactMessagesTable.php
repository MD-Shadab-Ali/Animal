<?php

namespace App\Filament\Resources\ContactMessages\Tables;

use App\Models\ContactMessage;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ContactMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                IconColumn::make('is_read')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-envelope-open')
                    ->falseIcon('heroicon-s-envelope')
                    ->trueColor('gray')
                    ->falseColor('warning'),

                TextColumn::make('name')
                    ->searchable()
                    ->weight(fn (ContactMessage $record) => $record->is_read ? null : 'bold')
                    ->description(fn (ContactMessage $record) => trim(($record->phone ?? '').' '.($record->email ?? '')) ?: null),

                TextColumn::make('subject')->placeholder('No subject')->limit(30),

                TextColumn::make('message')->wrap()->limit(90),

                IconColumn::make('admin_reply')
                    ->label('Replied')
                    ->boolean()
                    ->getStateUsing(fn (ContactMessage $record) => filled($record->admin_reply)),

                TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_read')
                    ->label('Status')
                    ->placeholder('All messages')
                    ->trueLabel('Read')
                    ->falseLabel('Unread'),
            ])
            ->recordActions([
                Action::make('reply')
                    ->label('Log reply')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('primary')
                    ->schema([
                        Textarea::make('admin_reply')
                            ->label('What did you tell them?')
                            ->rows(4)
                            ->required()
                            ->default(fn (ContactMessage $record) => $record->admin_reply),
                    ])
                    ->action(function (ContactMessage $record, array $data): void {
                        $record->update([
                            'admin_reply' => $data['admin_reply'],
                            'is_read'     => true,
                        ]);

                        Notification::make()->title('Reply saved against this message')->success()->send();
                    }),

                Action::make('toggleRead')
                    ->label(fn (ContactMessage $record) => $record->is_read ? 'Mark unread' : 'Mark read')
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->action(fn (ContactMessage $record) => $record->update(['is_read' => ! $record->is_read])),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markRead')
                        ->label('Mark as read')
                        ->icon('heroicon-o-envelope-open')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $records->each->update(['is_read' => true])),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Inbox is empty');
    }
}
