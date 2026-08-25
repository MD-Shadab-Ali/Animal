<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoatImage extends Model
{
    protected $fillable = ['goat_id', 'path', 'alt', 'sort_order'];

    public function goat(): BelongsTo
    {
        return $this->belongsTo(Goat::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? asset('storage/'.$this->path) : null;
    }
}
