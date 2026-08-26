<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * The two queues that need a person, then everything else.
     *
     * "To confirm delivered" is the one that is easy to miss: those orders are
     * paid for and out with the rider, so nothing is blocking them and nothing
     * will close them by itself — money cannot witness a delivery. Left
     * unconfirmed they also hold up the seller, whose earnings only settle once
     * the order is delivered.
     */
    public function getTabs(): array
    {
        return [
            'new' => Tab::make('New')
                ->badge(Order::where('status', 'pending')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'pending')),

            'to_confirm' => Tab::make('To confirm delivered')
                ->badge(Order::query()->awaitingDeliveryConfirmation()->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn ($query) => $query->awaitingDeliveryConfirmation()),

            'open' => Tab::make('In progress')
                ->modifyQueryUsing(fn ($query) => $query
                    ->whereIn('status', ['confirmed', 'processing', 'out_for_delivery'])),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
