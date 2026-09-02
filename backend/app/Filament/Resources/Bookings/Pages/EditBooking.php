<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    /**
     * The save is one transaction, and it has to be.
     *
     * Moving a booking's dates is two writes: the row itself, and the nights it
     * holds in `booking_nights`. The second happens in BookingObserver, after
     * the first has already been committed -- so without this, a date change
     * that clashed with another guest would throw *after* the booking had
     * quietly moved, leaving a stay whose dates say one thing and whose held
     * nights say another. The room would then be bookable on nights somebody
     * believed they had.
     *
     * Wrapped, the clash takes the whole save down and the booking is left
     * exactly as it was. Filament shows the message the service raised.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record->update($data);

            return $record;
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
