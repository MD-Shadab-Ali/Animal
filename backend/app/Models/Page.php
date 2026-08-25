<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Page extends Model
{
    protected $fillable = [
        'title', 'slug', 'excerpt', 'body', 'banner_image',
        'is_active', 'show_in_footer', 'meta_title', 'meta_description', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active'      => 'boolean',
            'show_in_footer' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $page) {
            if (blank($page->slug)) {
                $page->slug = Str::slug($page->title);
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getBannerImageUrlAttribute(): ?string
    {
        return $this->banner_image ? asset('storage/'.$this->banner_image) : null;
    }
}
