<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Services\ManualOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'New phone order';
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    /**
     * Orders are never a plain insert — totals, stock and snapshots all have to
     * be handled together, so this hands off to the same kind of service the
     * storefront checkout uses.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ManualOrderService::class)->create($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Order created and stock updated';
    }
}
