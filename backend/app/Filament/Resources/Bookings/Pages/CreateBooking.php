<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    /**
     * A booking taken over the phone goes through the same door as one taken
     * on the storefront.
     *
     * Filament would happily have written the row itself, and that is exactly
     * the problem: everything that makes a booking safe lives in
     * BookingService::place() -- the notice period, the room's own shortest and
     * longest stay, the guest count, the pricing, and the transaction that
     * writes the held nights alongside the booking. A form inserting directly
     * would have none of it, and staff would become the one route by which a
     * room could be sold twice.
     *
     * So the form collects and the service decides. A clash surfaces as a
     * validation error against the check-in field, which is where the person
     * typing is already looking.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $room = Room::findOrFail($data['room_id']);
        $guest = User::findOrFail($data['user_id']);

        return app(BookingService::class)->place($room, $guest, $data);
    }
}
