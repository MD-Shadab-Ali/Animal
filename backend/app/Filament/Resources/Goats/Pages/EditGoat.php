<?php

namespace App\Filament\Resources\Goats\Pages;

use App\Filament\Resources\Goats\GoatResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditGoat extends EditRecord
{
    protected static string $resource = GoatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
