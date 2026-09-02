<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night of one room, spoken for.
 *
 * The smallest thing this feature is actually about. A booking is a range, and
 * ranges cannot be made unique; a night can, and the unique index on
 * (room_id, night) is the only reason two guests cannot end up holding the same
 * bed. Everything else -- the availability query, the greyed-out dates in the
 * picker, the release of a cancelled stay -- falls out of these rows existing.
 *
 * There is nothing else on it and there should not be. It is an index of what
 * is occupied, not a record of anything: who booked it, when, and for how much
 * are all the booking's business, and a copy of any of that here would be a
 * second version of the truth with no constraint keeping it in step.
 */
class BookingNight extends Model
{
    /** An index row, not a record. Nothing here has a history worth stamping. */
    public $timestamps = false;

    protected $fillable = ['booking_id', 'room_id', 'night'];

    protected function casts(): array
    {
        return [
            'night' => 'date',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
