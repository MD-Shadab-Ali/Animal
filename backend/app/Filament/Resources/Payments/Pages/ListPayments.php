<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    /** Claims first — those are the ones that need a person. */
    public function getTabs(): array
    {
        return [
            'to_check' => Tab::make('To check')
                ->badge(Payment::where('status', 'pending')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'pending')),

            'received' => Tab::make('Received')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'confirmed')),

            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'rejected')),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Payment::where('status', 'pending')->exists() ? 'to_check' : 'all';
    }
}
