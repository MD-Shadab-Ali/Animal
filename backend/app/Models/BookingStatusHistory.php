<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every move a booking made, and who made it.
 *
 * The same record the order keeps, for the same reason: a status column says
 * where a stay is now and nothing about how it got there. When a guest says
 * they cancelled and the farm says they never turned up, this is the only thing
 * in the system that can settle it.
 */
class BookingStatusHistory extends Model
{
    protected $fillable = ['booking_id', 'user_id', 'from_status', 'to_status', 'note'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** Whoever moved it. Null when nothing human did -- a payment landing, say. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return Booking::STATUSES[$this->to_status] ?? $this->to_status;
    }
}
