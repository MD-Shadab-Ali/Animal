<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    protected $fillable = ['order_id', 'user_id', 'from_status', 'to_status', 'note', 'photo'];

    /** Stored as a path; the buyer needs something they can point an <img> at. */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo ? asset('storage/'.$this->photo) : null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Whether the buyer wrote this one about themselves.
     *
     * A buyer pressing "I'm on my way" moves the order and leaves a note --
     * "Buyer is on the way to collect." -- which is written for staff, in the
     * third person, about the person reading it. It was appearing under
     * "Updates from the farm", a heading that says the opposite of where it
     * came from.
     *
     * Rows with no user at all are the system's own ("Order placed"), and
     * those are not the buyer's either.
     */
    public function isFromBuyer(): bool
    {
        return $this->user_id !== null && $this->user_id === $this->order?->user_id;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
