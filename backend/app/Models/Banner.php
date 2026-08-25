<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'placement', 'title', 'subtitle', 'description', 'image', 'mobile_image',
        'button_text', 'button_link', 'text_align', 'overlay_color',
        'is_active', 'starts_at', 'ends_at', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
        ];
    }

    /** Active, and inside its scheduling window if one is set. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    public function getMobileImageUrlAttribute(): ?string
    {
        return $this->mobile_image ? asset('storage/'.$this->mobile_image) : $this->image_url;
    }
}
