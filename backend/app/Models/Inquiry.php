<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inquiry extends Model
{
    protected $fillable = ['goat_id', 'user_id', 'name', 'phone', 'email', 'message', 'status'];

    public function goat(): BelongsTo
    {
        return $this->belongsTo(Goat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
