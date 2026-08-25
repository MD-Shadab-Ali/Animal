<?php

namespace App\Filament\Resources\Goats\Pages;

use App\Filament\Resources\Goats\GoatResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGoats extends ListRecords
{
    protected static string $resource = GoatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
