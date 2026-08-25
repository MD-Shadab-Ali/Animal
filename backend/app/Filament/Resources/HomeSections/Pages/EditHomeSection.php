<?php

namespace App\Filament\Resources\HomeSections\Pages;

use App\Filament\Resources\HomeSections\HomeSectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHomeSection extends EditRecord
{
    protected static string $resource = HomeSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
