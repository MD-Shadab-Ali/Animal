<?php

namespace App\Filament\Resources\Refunds\Pages;

use App\Filament\Resources\Refunds\RefundResource;
use App\Models\Payment;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;

    /** What is owed comes first — a buyer is out of pocket until it is sent. */
    public function getTabs(): array
    {
        return [
            'to_send' => Tab::make('To send')
                ->badge(Payment::refunds()->where('status', 'pending')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'pending')),

            'sent' => Tab::make('Sent')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'confirmed')),

            'declined' => Tab::make('Declined')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'rejected')),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Payment::refunds()->where('status', 'pending')->exists() ? 'to_send' : 'all';
    }
}
