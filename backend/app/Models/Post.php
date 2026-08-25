<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Post extends Model
{
    protected $fillable = [
        'post_category_id', 'user_id', 'title', 'slug', 'excerpt', 'body',
        'cover_image', 'is_published', 'is_featured', 'published_at',
        'views', 'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_featured'  => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $post) {
            if (blank($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
            if ($post->is_published && blank($post->published_at)) {
                $post->published_at = now();
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'post_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        return $this->cover_image ? asset('storage/'.$this->cover_image) : null;
    }
}
